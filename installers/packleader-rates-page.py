#!/usr/bin/env python3
"""Publish the Pack Leader rates page and link it from the booking form. Run on fTower.

  https://office.example.com/?entryPoint=rates

1. Installs configs/espocrm/Rates.php as the `rates` entry point (a public
   page styled like the boarding terms page).
2. Writes the "Rates" knowledge base article from packleader_pricing.py, the
   same numbers the invoices use. Re-run this after changing a price.
3. Booking form text: adds a link to the rates page next to the terms link,
   and replaces the old money sentence (the $50 flat rate, rescues, and
   "due at drop-off"; then the over/under 40 lbs rates) with the current one.
4. Boarding terms article ("Boarding agreement terms", what clients agree to):
   its Payment paragraph, from the same numbers. Added 2026-09-28 with the
   weight tiers and puppy rate.

Dry run by default. Aborts if the form text isn't the audited wording.
Safe to re-run; it only writes what differs.

  packleader-rates-page.py            # show the plan
  packleader-rates-page.py --apply
"""
import argparse, base64, json, os, subprocess, sys, urllib.error, urllib.parse, urllib.request

HERE = os.path.dirname(os.path.abspath(__file__))
sys.path.insert(0, HERE)
from packleader_pricing import (BOARDING_NIGHT, CARD_FEE_PCT, DISCOUNT_PCT, PUPPY_NIGHT,  # noqa: E402
                                SIZE_LABEL, money, rates_html)

C = "espocrm"
ESPO = "http://127.0.0.1:8210/api/v1/"
ENV = "/opt/docker/espocrm/.env"
PHP_SRC = os.path.join(HERE, "..", "configs", "espocrm", "Rates.php")
PHP_DST = "custom/Espo/Custom/EntryPoints/Rates.php"
RATES_URL = "https://office.example.com?entryPoint=rates"
PUBLIC = "https://office.example.com/?entryPoint=rates"

TERMS_LINE = "Read the [boarding terms](https://office.example.com?entryPoint=boardingTerms) before you agree."
TERMS_LINE_NEW = TERMS_LINE + " See all our [rates](%s)." % RATES_URL
OLD_MONEY = ("Boarding is $50 a night. Military, seniors, and rescues get 20% off. "
             "We apply that when we confirm. Payment is due at drop-off.")
# The form's money sentence before 2026-09-28 (over/under 40 lbs).
PREV_MONEY = ("Boarding is $50 a night for dogs over 40 lbs and $40 under. Military, first responders, "
              "and seniors get 20% off with valid ID. We apply that when we confirm. When we confirm, "
              "we email an invoice. Payment is due by 8 PM the night before drop-off or the first class, "
              "and card payments carry a 3% fee.")


def boarding_words():
    """'$40 under 25 lbs, $45 for 25–50 lbs, ... and $60 for 100+ lbs. Puppies add ...'"""
    words = {"Under25": "under 25 lbs", "Over100": "for 100 lbs and up"}
    tiers = ["%s %s" % (money(c), words.get(k, "for " + SIZE_LABEL[k])) for k, c in BOARDING_NIGHT.items()]
    (_, young, _), (_, older, _) = PUPPY_NIGHT
    return ("%s, or %s, by the dog's weight on its vet records. Puppies add %s a night under 6 months old "
            "and %s a night from 6 to 12 months, by age at drop-off" % (", ".join(tiers[:-1]), tiers[-1],
                                                                        money(young), money(older)))


# The form's money sentence from 2026-09-28 to 2026-10-12 (weight tiers, card fee only).
LAST_MONEY = ("Boarding is per night: %s. Military, first responders, and seniors get %d%% off with valid ID. "
              "We apply that when we confirm. When we confirm, we email an invoice. Payment is due by 8 PM "
              "the night before drop-off or the first class, and card payments carry a %d%% fee."
              % (boarding_words(), DISCOUNT_PCT, CARD_FEE_PCT))
# Since 2026-10-12 every invoice offers bank (no fee) or card (the fee).
NEW_MONEY = ("Boarding is per night: %s. Military, first responders, and seniors get %d%% off with valid ID. "
             "We apply that when we confirm. When we confirm, we email an invoice. Payment is due by 8 PM "
             "the night before drop-off or the first class. Pay from your bank account with no fee, or by "
             "card with a %d%% fee." % (boarding_words(), DISCOUNT_PCT, CARD_FEE_PCT))

TERMS_ARTICLE = "Boarding agreement terms"
# The terms' Payment paragraph as packleader-golive.py left it (over/under 40 lbs).
PREV_PAY = ("<p><strong>Payment.</strong> Boarding is $50 per night for dogs over 40 lbs and $40 per night "
            "for dogs under 40 lbs. Military (active duty and veterans), first responders, and seniors (65+) "
            "receive 20% off with valid ID. Payment is due by 8 PM the night before drop-off or the first "
            "class, by the invoice emailed when the booking is approved, unless otherwise agreed. Card "
            "payments carry a 3% processing fee, shown on the invoice. A cancellation less than 48 hours "
            "before the start date may be charged one night.</p>")
# The Payment paragraph from 2026-09-28 to 2026-10-12.
LAST_PAY = ("<p><strong>Payment.</strong> Boarding is per night: %s. Military (active duty and veterans), "
            "first responders, and seniors (65+) receive %d%% off with valid ID. Payment is due by 8 PM the "
            "night before drop-off or the first class, by the invoice emailed when the booking is approved, "
            "unless otherwise agreed. Card payments carry a %d%% processing fee, shown on the invoice. A "
            "cancellation less than 48 hours before the start date may be charged one night.</p>"
            % (boarding_words(), DISCOUNT_PCT, CARD_FEE_PCT))
NEW_PAY = ("<p><strong>Payment.</strong> Boarding is per night: %s. Military (active duty and veterans), "
           "first responders, and seniors (65+) receive %d%% off with valid ID. Payment is due by 8 PM the "
           "night before drop-off or the first class, by the invoice emailed when the booking is approved, "
           "unless otherwise agreed. The invoice can be paid from a bank account with no fee, or by card "
           "with a %d%% processing fee. A cancellation less than 48 hours before the start date may be "
           "charged one night.</p>" % (boarding_words(), DISCOUNT_PCT, CARD_FEE_PCT))


def auth():
    env = dict(l.strip().split("=", 1) for l in open(ENV) if "=" in l and not l.startswith("#"))
    pw = env["ADMIN_PASSWORD"].strip().strip('"').strip("'")
    return {"Espo-Authorization": base64.b64encode(("admin:" + pw).encode()).decode(),
            "Content-Type": "application/json"}


H = auth()


def api(method, path, body=None, params=None):
    url = ESPO + path + ("?" + urllib.parse.urlencode(params, doseq=True) if params else "")
    r = urllib.request.Request(url, method=method, headers=H,
                               data=json.dumps(body).encode() if body is not None else None)
    try:
        with urllib.request.urlopen(r, timeout=60) as x:
            raw = x.read()
    except urllib.error.HTTPError as e:
        sys.exit("API %s %s -> %s %s" % (method, path, e.code, e.headers.get("X-Status-Reason") or ""))
    return json.loads(raw) if raw.strip() else None


def sh(*cmd, stdin=None):
    r = subprocess.run(cmd, input=stdin, capture_output=True, text=True)
    if r.returncode:
        sys.exit("FAILED %s\n%s" % (" ".join(cmd), r.stderr))
    return r.stdout


def main():
    ap = argparse.ArgumentParser(description=__doc__.split("\n")[0])
    ap.add_argument("--apply", action="store_true")
    apply = ap.parse_args().apply
    tag = "" if apply else "[dry run] "

    # ---- preflight
    php = open(PHP_SRC).read()
    live_php = subprocess.run(["docker", "exec", C, "cat", PHP_DST], capture_output=True, text=True).stdout
    owner = sh("docker", "exec", C, "stat", "-c", "%U", "custom/Espo/Custom/EntryPoints/BoardingTerms.php").strip()
    forms = api("GET", "LeadCapture", params={"maxSize": 50})["list"]
    if len(forms) != 1:
        sys.exit("expected one booking form, found %d" % len(forms))
    form = api("GET", "LeadCapture/" + forms[0]["id"])
    text = form.get("formText") or ""
    new_text = text
    if TERMS_LINE_NEW not in new_text:
        if new_text.count(TERMS_LINE) != 1:
            sys.exit("form text: the terms line isn't the audited wording. Stopping.")
        new_text = new_text.replace(TERMS_LINE, TERMS_LINE_NEW)
    was_money = next((m for m in (OLD_MONEY, PREV_MONEY, LAST_MONEY) if m in new_text), None)
    if NEW_MONEY not in new_text:
        if not was_money or new_text.count(was_money) != 1:
            sys.exit("form text: the payment sentence isn't the audited wording. Stopping.")
        new_text = new_text.replace(was_money, NEW_MONEY)
    terms = api("GET", "KnowledgeBaseArticle", params={"where[0][type]": "equals",
                                                       "where[0][attribute]": "name",
                                                       "where[0][value]": TERMS_ARTICLE})["list"]
    if len(terms) != 1:
        sys.exit("expected one %r article, found %d. Stopping." % (TERMS_ARTICLE, len(terms)))
    terms_body = api("GET", "KnowledgeBaseArticle/" + terms[0]["id"]).get("body") or ""
    was_pay = next((x for x in (PREV_PAY, LAST_PAY) if terms_body.count(x) == 1), None)
    if NEW_PAY not in terms_body and not was_pay:
        sys.exit("terms: the Payment paragraph isn't the audited wording (was it edited?). Stopping.")
    arts = api("GET", "KnowledgeBaseArticle", params={"where[0][type]": "equals",
                                                      "where[0][attribute]": "name",
                                                      "where[0][value]": "Rates"})["list"]
    if len(arts) > 1:
        sys.exit("more than one article named Rates. Stopping.")
    body = rates_html()
    old_body = api("GET", "KnowledgeBaseArticle/" + arts[0]["id"]).get("body") if arts else None
    print("preflight ok (entry point files owned by %s)" % owner)

    # ---- 1. entry point
    if live_php == php:
        print("entry point: up to date")
    else:
        print("%sentry point: %s %s" % (tag, "install" if not live_php else "update", PHP_DST))
        if apply:
            sh("docker", "exec", "-i", "-u", owner, C, "sh", "-c", 'cat > "$1"', "-", PHP_DST, stdin=php)
            sh("docker", "exec", "-u", owner, C, "bin/command", "clear-cache")

    # ---- 2. article
    if old_body == body:
        print("Rates article: up to date")
    else:
        print("%sRates article: %s (%d sections)" % (tag, "update" if arts else "create", body.count("<h2>")))
        if apply:
            if arts:
                api("PUT", "KnowledgeBaseArticle/" + arts[0]["id"], {"body": body, "status": "Published"})
            else:
                api("POST", "KnowledgeBaseArticle", {"name": "Rates", "status": "Published",
                                                     "language": "en_US", "body": body})

    # ---- 3. form text
    if new_text == text:
        print("form text: up to date")
    else:
        print("%sform text:\n  - %s\n  + %s" % (tag, was_money, NEW_MONEY)
              if was_money else "%sform text: add the rates link" % tag)
        if apply:
            api("PUT", "LeadCapture/" + form["id"], {"formText": new_text})

    # ---- 4. boarding terms, Payment paragraph
    if NEW_PAY in terms_body:
        print("terms: up to date")
    else:
        print("%sterms, Payment paragraph:\n  - %s\n  + %s" % (tag, was_pay, NEW_PAY))
        if apply:
            api("PUT", "KnowledgeBaseArticle/" + terms[0]["id"], {"body": terms_body.replace(was_pay, NEW_PAY)})

    # ---- verify the public page
    if apply:
        try:
            # Cloudflare answers the default Python user agent with a 403
            req = urllib.request.Request(PUBLIC, headers={"User-Agent": "Mozilla/5.0 (pk9 rates check)"})
            with urllib.request.urlopen(req, timeout=30) as x:
                page = x.read().decode()
            print("verify: %s %s" % (PUBLIC, "shows the new rates" if "75–100 lbs" in page else "DOES NOT show the new rates"))
        except urllib.error.HTTPError as e:
            print("verify: %s -> HTTP %s" % (PUBLIC, e.code))
    else:
        print("\nNothing changed. Run again with --apply.")


if __name__ == "__main__":
    main()
