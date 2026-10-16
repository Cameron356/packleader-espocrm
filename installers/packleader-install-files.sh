#!/usr/bin/env bash
# Install the office book's PHP pages from configs/espocrm/ into the live EspoCRM, without the
# full installer (which signs in as "admin" by password and fails now that 2FA is on).
# Backs up custom/ first, refuses if a live file matches no version in git history (someone
# edited it outside git), installs, checks each file byte for byte, then rebuilds.
#   scripts/packleader-install-files.sh            # dry run
#   scripts/packleader-install-files.sh --apply
# PK9_CONTAINER=pk9dev-espocrm targets the DEV book.
set -euo pipefail
cd "$(dirname "$0")/.."
C=${PK9_CONTAINER:-espocrm}
FILES=(
  "ShotRecords.php:custom/Espo/Custom/EntryPoints/ShotRecords.php"
  "Booking.php:custom/Espo/Custom/EntryPoints/Booking.php"
  "Client.php:custom/Espo/Custom/EntryPoints/Client.php"
  "KennelBoard.php:custom/Espo/Custom/EntryPoints/KennelBoard.php"
  "BookAgainSubmit.php:custom/Espo/Custom/Api/BookAgainSubmit.php"
)
apply=0; [ "${1:-}" = "--apply" ] && apply=1

todo=(); drift=0
for pair in "${FILES[@]}"; do
  src=configs/espocrm/${pair%%:*}; dst=${pair#*:}
  live=$(docker exec "$C" cat "$dst" 2>/dev/null || true)
  if cmp -s <(printf '%s' "$live") <(printf '%s' "$(cat "$src")"); then
    echo "same:    $src"; continue
  fi
  known=0   # the live file must be some version git has seen, or someone edited it by hand
  for rev in $(git rev-list HEAD -- "$src"); do
    if cmp -s <(printf '%s' "$live") <(printf '%s' "$(git show "$rev:$src" 2>/dev/null)"); then known=1; break; fi
  done
  if [ $known = 0 ] && [ -n "$live" ]; then
    echo "DRIFT:   $dst matches no committed version of $src"; drift=1
  fi
  echo "change:  $src -> $dst"; todo+=("$pair")
done
[ $drift = 1 ] && { echo "Stopped: a live file was edited outside git. Compare it before installing."; exit 1; }
[ ${#todo[@]} = 0 ] && { echo "Nothing to install."; exit 0; }
[ $apply = 0 ] && { echo "Dry run. Run again with --apply."; exit 0; }

backup=~/espocrm-custom-backup-$(date +%Y%m%d-%H%M%S).tgz
docker exec "$C" tar czf - custom > "$backup"; chmod 600 "$backup"; echo "backup:  $backup"
owner=$(docker exec "$C" stat -c %U custom/Espo/Custom/EntryPoints/Client.php)
for pair in "${todo[@]}"; do
  src=configs/espocrm/${pair%%:*}; dst=${pair#*:}
  docker exec -i -u "$owner" "$C" sh -c "cat > '$dst.new' && mv '$dst.new' '$dst'" < "$src"
  docker exec "$C" cat "$dst" | cmp -s - "$src" && echo "ok:      $dst" || { echo "FAILED:  $dst"; exit 1; }
done
docker exec -u "$owner" "$C" php command.php rebuild | tail -1
echo "Undo: docker exec -i $C tar xzf - < $backup && docker exec -u $owner $C php command.php rebuild"
