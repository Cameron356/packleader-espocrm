#!/usr/bin/env bash
# Staff role for the Pack Leader app (pk9-app docs/specs/staff-role.md): regular users who do the
# daily care (check in/out, care checklist, report cards, lessons) but no money and no requests.
# Idempotent. The live book gets the role only (the owner creates staff users in the EspoCRM UI and
# assigns the role); the dev book can also make a TOTP staff user for the e2e.
#   bash scripts/packleader-add-staff-role.sh                                  # live book: the role
#   bash scripts/packleader-add-staff-role.sh pk9dev-espocrm 8241 staff-dev ~/pk9-dev/staff-dev.env
# Role "Staff": read/edit Stay, Meeting; read Dog, Contact, LessonPackage; create/read/edit/delete
# StayLog, TrainingGoal. No Lead, no RequestDog: requests and money are the owner's.
# Runs inside the office-book container with EspoCRM's own code (no admin login needed).
set -euo pipefail
cd "$(dirname "$0")/.."
C="${1:-espocrm}"
PORT="${2:-8210}"
STAFF="${3:-}"        # optional username of a new staff user (dev book only)
KEYFILE="${4:-}"      # where its password + TOTP secret go (600, never printed)

docker inspect -f '{{.Name}}' "$C" >/dev/null

# 1. The role.
docker exec -i -u www-data "$C" php -r '
require "bootstrap.php";
$app = new \Espo\Core\Application();
$app->setupSystemUser();
$em = $app->getContainer()->getByClass(\Espo\ORM\EntityManager::class);
$data = [
    "Stay"          => ["create" => "no", "read" => "all", "edit" => "all", "delete" => "no", "stream" => "yes"],
    "Dog"           => ["create" => "no", "read" => "all", "edit" => "no", "delete" => "no", "stream" => "no"],
    "Contact"       => ["create" => "no", "read" => "all", "edit" => "no", "delete" => "no", "stream" => "no"],
    "Meeting"       => ["create" => "no", "read" => "all", "edit" => "all", "delete" => "no", "stream" => "yes"],
    "LessonPackage" => ["create" => "no", "read" => "all", "edit" => "no", "delete" => "no", "stream" => "no"],
    "StayLog"       => ["create" => "all", "read" => "all", "edit" => "all", "delete" => "all", "stream" => "yes"],
    "TrainingGoal"  => ["create" => "all", "read" => "all", "edit" => "all", "delete" => "all", "stream" => "yes"],
];
$role = $em->getRDBRepository("Role")->where(["name" => "Staff"])->findOne() ?? $em->getNewEntity("Role");
$role->set(["name" => "Staff", "data" => (object) $data, "fieldData" => (object) [],
            "assignmentPermission" => "no", "userPermission" => "no"]);
$em->saveEntity($role);
fwrite(STDERR, "role: Staff (" . $role->getId() . ")\n");
'
docker exec -u www-data "$C" bin/command clear-cache >/dev/null
echo "role: Staff ready"

# 2. A staff user with TOTP (dev book only; live users are made in the EspoCRM UI).
if [ -n "$STAFF" ]; then
  [ "$C" = "pk9dev-espocrm" ] || { echo "staff users are created in the EspoCRM UI for the live book" >&2; exit 1; }
  [ -n "$KEYFILE" ] || { echo "a key file is required with a staff user" >&2; exit 1; }
  python3 - "$STAFF" "$KEYFILE" <<'PY'
# Mirrors dev/office/twofa_spike.py's enrol flow (admin basic auth works on the dev book).
import base64, hashlib, hmac, json, os, secrets, struct, sys, time, urllib.error, urllib.request

BASE = "http://127.0.0.1:8241/api/v1/"
D = os.path.expanduser("~/pk9-dev")
assert BASE.startswith("http://127.0.0.1:8241/"), "dev office book only"
STAFF, KEYFILE = sys.argv[1], sys.argv[2]


def envfile(p):
    out = {}
    if os.path.exists(p):
        for line in open(p):
            if "=" in line and not line.startswith("#"):
                k, v = line.rstrip("\n").split("=", 1)
                out[k] = v
    return out


def b64(s):
    return base64.b64encode(s.encode()).decode()


def totp(secret_b32, at=None, step=30, digits=6):
    key = base64.b32decode(secret_b32.upper() + "=" * (-len(secret_b32) % 8))
    counter = int((at or time.time()) // step)
    mac = hmac.new(key, struct.pack(">Q", counter), hashlib.sha1).digest()
    o = mac[-1] & 0x0F
    return str((struct.unpack(">I", mac[o:o + 4])[0] & 0x7FFFFFFF) % 10 ** digits).zfill(digits)


def call(method, path, headers=None, body=None):
    h = {"Content-Type": "application/json"}
    h.update(headers or {})
    r = urllib.request.Request(BASE + path, method=method, headers=h,
                               data=json.dumps(body).encode() if body is not None else None)
    try:
        with urllib.request.urlopen(r, timeout=20) as x:
            raw = x.read()
            return x.status, json.loads(raw) if raw.strip() else None
    except urllib.error.HTTPError as e:
        raw = e.read()
        try:
            data = json.loads(raw) if raw.strip() else None
        except ValueError:
            data = raw[:200].decode(errors="replace")
        return e.code, data


admin_pw = envfile(os.path.join(D, ".env"))["ADMIN_PASSWORD"]
ADMIN = {"Espo-Authorization": b64("admin:" + admin_pw)}

# create (or keep) the user, type regular
s, d = call("GET", "User?select=id,userName&where[0][type]=equals&where[0][attribute]=userName&where[0][value]=" + STAFF, ADMIN)
found = (d or {}).get("list") or []
if found:
    uid = found[0]["id"]
    print("staff user", STAFF, "exists")
else:
    pw = secrets.token_urlsafe(18)
    s, d = call("POST", "User", ADMIN, {"userName": STAFF, "firstName": "Staff", "lastName": "Dev",
                                        "type": "regular", "password": pw, "passwordConfirm": pw})
    if s != 200:
        sys.exit("POST User failed: %s %s" % (s, d))
    uid, new_pw = d["id"], pw
    print("staff user", STAFF, "created")
    me = {"Espo-Authorization": b64(STAFF + ":" + new_pw)}
    s, d = call("POST", "UserSecurity/action/getTwoFactorUserSetupData", me,
                {"id": uid, "password": new_pw, "auth2FAMethod": "Totp", "reset": True})
    if s != 200:
        sys.exit("2FA setup data failed: %s %s" % (s, d))
    secret = d["auth2FATotpSecret"]
    s, d = call("PUT", "UserSecurity/" + uid, me,
                {"password": new_pw, "auth2FA": True, "auth2FAMethod": "Totp", "code": totp(secret)})
    if s != 200:
        sys.exit("2FA enrol failed: %s %s" % (s, d))
    print("staff user", STAFF, "TOTP enrolled")
    with os.fdopen(os.open(KEYFILE, os.O_WRONLY | os.O_CREAT | os.O_TRUNC, 0o600), "w") as f:
        f.write("USER=%s\nPASSWORD=%s\nTOTP_SECRET=%s\n" % (STAFF, new_pw, secret))
    print("creds ->", KEYFILE, "(600, not printed)")

# relate the Staff role
s, d = call("GET", "Role?select=id,name&where[0][type]=equals&where[0][attribute]=name&where[0][value]=Staff", ADMIN)
roles = (d or {}).get("list") or []
if not roles:
    sys.exit("Staff role not found")
call("POST", "User/%s/roles" % uid, ADMIN, {"id": roles[0]["id"]})
print("Staff role related to", STAFF)
PY
fi
echo "staff role ready"
