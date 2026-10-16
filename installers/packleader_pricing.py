"""Pack Leader K9 price list, as code.

Source: her pricing sheet (Training Services & Pricing, 2026-09). Change a
number here and the next invoice uses it. Amounts are cents.

price_request() takes the fields of an office-book Request (Lead) plus the
group class it is linked to, and returns the invoice lines, or raises
PriceError with a sentence she can act on. Nothing here talks to a network.
"""
import datetime as dt
import json

# Boarding, per night, by weight (from the vet records). Changed 2026-09-28 from
# over/under 40 lbs ($50/$40); bookings made before then keep their old price.
BOARDING_NIGHT = {"Under25": 4000, "From25to50": 4500, "From50to75": 5000,
                  "From75to100": 5500, "Over100": 6000}
SIZE_LABEL = {"Under25": "Under 25 lbs", "From25to50": "25–50 lbs", "From50to75": "50–75 lbs",
              "From75to100": "75–100 lbs", "Over100": "100+ lbs"}
# The old sizes stay on dogs and bookings from before 2026-09-28; they can't be priced.
OLD_SIZE_LABEL = {"Over40": "Over 40 lbs (old)", "Under40": "Under 40 lbs (old)"}
# Puppy upcharge per boarding night, by age on the drop-off date (birth date is required).
PUPPY_NIGHT = ((6, 2500, "under 6 months"), (12, 1500, "6–12 months"))
BOARD_TRAIN_WEEK = 120000
BOARD_TRAIN_WEEK_LONG = 100000          # 4 weeks or more
BOARD_TRAIN_LONG_WEEKS = 4
DAY_TRAINING = {"weekday": 17500, "saturday": 22500}   # no Sundays
PRIVATE_PACK = {"Single": (1, 10000), "Pack3": (3, 28500),
                "Pack5": (5, 45000), "Pack10": (10, 85000)}
GROUP_CLASS = {"BasicObedience": ("Basic Obedience", 20000, 10),
               "AdvancedObedience": ("Advanced Obedience", 20000, 10),
               "OffLeashBasic": ("Off-Leash Basic", 40000, 5),
               "OffLeashAdvanced": ("Off-Leash Advanced", 40000, 5)}
DISCOUNT_PCT = 20
DISCOUNT_LABEL = {"Military": "Military", "FirstResponder": "First responder",
                  "Senior": "Senior", "Rescue": "Rescue"}
# Florida allows a card surcharge; card-network rules cap it at 3%.
CARD_FEE_PCT = 3


class PriceError(Exception):
    pass


def money(cents):
    return "${:,.2f}".format(cents / 100).replace(".00", "")


def _date(s, what):
    if not s:
        raise PriceError("%s date is empty." % what)
    return dt.date.fromisoformat(s[:10])


def _span(r):
    a, d = _date(r.get("arrive"), "Arrive"), _date(r.get("depart"), "Depart")
    if d < a:
        raise PriceError("Depart is before arrive.")
    return a, d


def fmt_dates(a, d=None):
    if not d or d == a:
        return a.strftime("%a %b %-d, %Y")
    return "%s – %s" % (a.strftime("%a %b %-d"), d.strftime("%a %b %-d, %Y"))


def age_months(birth, on):
    """Whole months old on the date `on` (a dog born Mar 15 is 6 months old on Sep 15)."""
    m = (on.year - birth.year) * 12 + on.month - birth.month
    return m - 1 if on.day < birth.day else m


def puppy_night(birth, on):
    """(cents per night, label) for a puppy on the drop-off date, or (0, None)."""
    months = age_months(birth, on)
    for limit, cents, label in PUPPY_NIGHT:
        if months < limit:
            return cents, label
    return 0, None


def boarding_night(size, birth, arrive):
    """Nightly boarding cents (weight rate + puppy upcharge), or None if the size is unknown."""
    rate = BOARDING_NIGHT.get(size or "")
    if rate is None or not birth:
        return None
    return rate + puppy_night(dt.date.fromisoformat(str(birth)[:10]), arrive)[0]


def price_request(r, group_class=None, card_fee=True):
    """r: Lead fields. group_class: the linked Meeting (dict) or None.

    Returns dict(lines=[(description, cents)], start=date, summary=str).
    lines already include the discount and, when card_fee, the card fee.
    """
    svc, dog = r.get("service"), r.get("dogName") or "your dog"
    override = r.get("price")
    lines = []

    if svc == "Boarding":
        a, d = _span(r)
        nights = (d - a).days
        if nights < 1:
            raise PriceError("Boarding needs at least one night. For a day visit, use Day stay & train.")
        size = r.get("dogSize") or ""
        rate = BOARDING_NIGHT.get(size)
        if rate is None and override is None:
            raise PriceError("Set the dog's size from the vet records (%s)%s, so the nightly rate is known." % (
                " / ".join(SIZE_LABEL.values()),
                "; %s is from before the new rates" % OLD_SIZE_LABEL[size] if size in OLD_SIZE_LABEL else ""))
        if not r.get("birthDate") and override is None:
            raise PriceError("Set the dog's birth date, so the puppy rate is known (under 6 months +%s a night, "
                             "6–12 months +%s)." % (money(PUPPY_NIGHT[0][1]), money(PUPPY_NIGHT[1][1])))
        summary = "Boarding for %s, %s" % (dog, fmt_dates(a, d))
        if override is None:
            n = "%d night%s" % (nights, "" if nights == 1 else "s")
            lines.append(("Boarding (%s), %s × %s (%s)" % (
                SIZE_LABEL[size], n, money(rate), fmt_dates(a, d)), nights * rate))
            pup, label = puppy_night(dt.date.fromisoformat(str(r["birthDate"])[:10]), a)
            if pup:
                lines.append(("Puppy, %s at drop-off, %s × %s" % (label, n, money(pup)), nights * pup))
        start = a

    elif svc == "BoardAndTrain":
        a, d = _span(r)
        nights = (d - a).days
        summary = "Board & train for %s, %s" % (dog, fmt_dates(a, d))
        if override is None:
            if nights < 7 or nights % 7:
                raise PriceError("Board & train is priced by the week, and %d nights is not whole weeks. "
                                 "Fix the dates or type the total in Price." % nights)
            weeks = nights // 7
            rate = BOARD_TRAIN_WEEK_LONG if weeks >= BOARD_TRAIN_LONG_WEEKS else BOARD_TRAIN_WEEK
            lines.append(("Board & train, %d week%s × %s (%s)" % (
                weeks, "" if weeks == 1 else "s", money(rate), fmt_dates(a, d)), weeks * rate))
        start = a

    elif svc == "DayStayAndTrain":
        a = _date(r.get("arrive"), "Arrive")
        d = _date(r.get("depart"), "Depart") if r.get("depart") else a
        if d < a:
            raise PriceError("Depart is before arrive.")
        summary = "Day training for %s, %s" % (dog, fmt_dates(a, d))
        if override is None:
            day = a
            while day <= d:
                wd = day.weekday()
                if wd == 6:
                    raise PriceError("%s is a Sunday. Day training runs Monday to Saturday." % fmt_dates(day))
                kind = "saturday" if wd == 5 else "weekday"
                lines.append(("Day training, %s" % fmt_dates(day), DAY_TRAINING[kind]))
                day += dt.timedelta(days=1)
        start = a

    elif svc == "PrivateLesson":
        if not r.get("lessonStart"):
            raise PriceError("Set Lesson start (the first session's date and time).")
        start = _date(r["lessonStart"], "Lesson start")
        pack = r.get("lessonPackage") or "Single"
        if pack not in PRIVATE_PACK:
            raise PriceError("Unknown lesson package %r." % pack)
        n, cents = PRIVATE_PACK[pack]
        summary = "Private training for %s, first session %s" % (dog, fmt_dates(start))
        if override is None:
            lines.append(("Private training, %s" % ("single session" if n == 1 else "%d-session pack" % n), cents))

    elif svc == "GroupClass":
        if not group_class:
            raise PriceError("Pick the group class this dog is joining (Group class field).")
        ctype = group_class.get("classType")
        if not group_class.get("dateStart"):
            raise PriceError("The group class has no start time.")
        start = _date(group_class["dateStart"], "Class start")
        info = GROUP_CLASS.get(ctype or "")
        if info is None and override is None:
            raise PriceError("The group class %r has no class type set, so the price is unknown. "
                             "Set Class type on the class, or type the total in Price." % group_class.get("name"))
        name = info[0] if info else group_class.get("name") or "Group class"
        summary = "%s for %s, starting %s" % (name, dog, fmt_dates(start))
        if override is None:
            lines.append(("%s (group class, starts %s)" % (name, fmt_dates(start)), info[1]))

    else:
        raise PriceError("No service on this request.")

    if override is not None:
        cents = int(round(float(override) * 100))
        if cents <= 0:
            raise PriceError("Price must be more than zero.")
        lines.append((summary, cents))

    subtotal = sum(c for _, c in lines)
    disc = r.get("discount") or ""
    if disc:
        if disc not in DISCOUNT_LABEL:
            raise PriceError("Unknown discount %r." % disc)
        cut = int(round(subtotal * DISCOUNT_PCT / 100))
        lines.append(("%s discount, %d%% off" % (DISCOUNT_LABEL[disc], DISCOUNT_PCT), -cut))
        subtotal -= cut
    if CARD_FEE_PCT and card_fee:
        lines.append(("Card processing fee, %d%%" % CARD_FEE_PCT, int(round(subtotal * CARD_FEE_PCT / 100))))
    return {"lines": lines, "start": start, "summary": summary,
            "total": sum(c for _, c in lines)}


def rates_html():
    """The public Rates page body, built from the numbers above (so the page and
    the invoices can't disagree). Published by packleader-rates-page.py."""
    def rows(items):
        return "<table>%s</table>" % "".join(
            "<tr><td>%s</td><td>%s</td></tr>" % (a, b) for a, b in items)
    pk = PRIVATE_PACK
    each = lambda k: money(pk[k][1] // pk[k][0])
    parts = [
        "<p class=\"tag\">Once you're in the pack, you're in for life.</p>",
        "<h2>Private training</h2><p>Sessions built around your dog.</p>",
        rows([("Single session", money(pk["Single"][1])),
              ("3-pack (%s a session)" % each("Pack3"), money(pk["Pack3"][1])),
              ("5-pack (%s a session)" % each("Pack5"), money(pk["Pack5"][1])),
              ("10-pack (%s a session)" % each("Pack10"), money(pk["Pack10"][1]))]),
        "<h2>Group classes</h2><p>Real-world obedience in a small group.</p>",
        rows([("%s (up to %d dogs)" % (n, cap), money(c)) for n, c, cap in GROUP_CLASS.values()]),
        "<h2>Board &amp; train</h2><p>Your dog stays with us for full training immersion: daily "
        "structured training, socialization and enrichment, weekly private lessons with you, "
        "progress updates, and a go-home session with ongoing support.</p>",
        rows([("Per week", money(BOARD_TRAIN_WEEK)),
              ("Per week, %d weeks or more" % BOARD_TRAIN_LONG_WEEKS, money(BOARD_TRAIN_WEEK_LONG))]),
        "<h2>Boarding</h2><p>Safe, all-inclusive overnight care. Training clients and new guests welcome. "
        "Priced per night by your dog's weight on its vet records.</p>",
        rows([("%s, per night" % SIZE_LABEL[k], money(c)) for k, c in BOARDING_NIGHT.items()]),
        "<p>Puppies, per night on top of the rate above, by age at drop-off:</p>",
        rows([("Puppy %s" % label, "+" + money(c)) for _, c, label in PUPPY_NIGHT]),
        "<h2>Day training</h2><p>Drop off for a day of training, 9 AM to 5 PM.</p>",
        rows([("Monday to Friday", money(DAY_TRAINING["weekday"])),
              ("Saturday", money(DAY_TRAINING["saturday"]))]),
        "<h2>We honor our heroes</h2><p>%d%% off every service for military (active duty and "
        "veterans), first responders (police, fire, EMS), and seniors (65+). Show a valid ID at "
        "booking.</p>" % DISCOUNT_PCT,
        "<h2>Paying</h2><p>When we confirm your booking, we email an invoice. Payment is due by "
        "8 PM the night before drop-off or the first class. Pay from your bank account with no fee, "
        "or by card with a %d%% processing fee.</p>" % CARD_FEE_PCT,
    ]
    return "\n".join(parts)


def dog_names(names):
    names = [n for n in names if n] or ["your dog"]
    return names[0] if len(names) == 1 else ", ".join(names[:-1]) + " & " + names[-1]


def price_dogs(r, dogs, group_class=None, card_fee=True):
    """Price a request with one or more dogs, full price per dog.

    dogs: list of dicts with at least "name" and, for boarding, "dogSize" and "birthDate".
    Empty or one dog = exactly price_request(). Several dogs = each dog's
    service lines, prefixed with its name, then ONE discount and ONE card fee
    on the total. Price (override) on the request replaces the whole total.
    """
    if len(dogs or []) <= 1:
        d = (dogs or [{}])[0]
        rd = dict(r, dogName=d.get("name") or r.get("dogName"), dogSize=d.get("dogSize") or r.get("dogSize"),
                  birthDate=d.get("birthDate") or r.get("birthDate"))
        return price_request(rd, group_class, card_fee)
    lines, start = [], None
    for d in dogs:
        rd = dict(r, dogName=d.get("name"), dogSize=d.get("dogSize"), birthDate=d.get("birthDate"),
                  price=None, discount="")
        try:
            p = price_request(rd, group_class, card_fee=False)
        except PriceError as e:
            raise PriceError("%s: %s" % (d.get("name") or "A dog", e))
        start = p["start"]
        lines += [("%s: %s" % (d.get("name"), desc), c) for desc, c in p["lines"]]
    everyone = dog_names([d.get("name") for d in dogs])
    summary = price_request(dict(r, dogName=everyone, dogSize=dogs[0].get("dogSize"),
                                 price=1, discount=""), group_class, card_fee=False)["summary"]
    if r.get("price") is not None:
        cents = int(round(float(r["price"]) * 100))
        if cents <= 0:
            raise PriceError("Price must be more than zero.")
        lines = [(summary, cents)]
    subtotal = sum(c for _, c in lines)
    disc = r.get("discount") or ""
    if disc:
        if disc not in DISCOUNT_LABEL:
            raise PriceError("Unknown discount %r." % disc)
        cut = int(round(subtotal * DISCOUNT_PCT / 100))
        lines.append(("%s discount, %d%% off" % (DISCOUNT_LABEL[disc], DISCOUNT_PCT), -cut))
        subtotal -= cut
    if CARD_FEE_PCT and card_fee:
        lines.append(("Card processing fee, %d%%" % CARD_FEE_PCT, int(round(subtotal * CARD_FEE_PCT / 100))))
    return {"lines": lines, "start": start, "summary": summary, "total": sum(c for _, c in lines)}


# Custom invoices (product sales, one-offs; spec pk9-app docs/specs/custom-invoices.md §2).
# The Lead's invoiceLines: JSON [{"d": description, "q": quantity, "c": unit cents, "t": taxable}].
CUSTOM_MAX_LINES, CUSTOM_MAX_DESC, CUSTOM_MAX_QTY, CUSTOM_MAX_CENTS = 20, 120, 99, 1000000
SALES_TAX_LABEL = "Florida sales tax, %s%% (Pinellas)"
CUSTOM_SUMMARY = "Invoice from Pack Leader K9"


def _half_up(n, d):
    """n / d rounded to the nearest whole cent, halves up (as the app's preview rounds). n, d >= 0 ints."""
    return (2 * n + d) // (2 * d)


def pct_text(p):
    """7.0 -> '7', 7.5 -> '7.5'."""
    return ("%.4f" % p).rstrip("0").rstrip(".")


def price_custom(r, tax_pct=7.0, card_fee=True):
    """A custom invoice from the Lead's invoiceLines. Same shape as price_request():
    lines (each item, then the sales tax line if any line is taxable, then the card fee
    when card_fee), start (today), summary, total. Raises PriceError in plain words."""
    raw = r.get("invoiceLines")
    if raw in (None, "", []):
        raise PriceError("This custom invoice has no lines (Invoice lines is empty).")
    if isinstance(raw, str):
        try:
            items = json.loads(raw)
        except ValueError:
            raise PriceError("Invoice lines can't be read (not valid JSON). Make the invoice again in the app.")
    else:
        items = raw
    if not isinstance(items, list) or not items:
        raise PriceError("Invoice lines must be a list of 1 to %d lines." % CUSTOM_MAX_LINES)
    if len(items) > CUSTOM_MAX_LINES:
        raise PriceError("A custom invoice can have at most %d lines; this one has %d." % (CUSTOM_MAX_LINES, len(items)))
    try:
        pct = float(tax_pct)
    except (TypeError, ValueError):
        raise PriceError("The sales tax rate %r isn't a number." % (tax_pct,))
    if not 0 <= pct <= 20:
        raise PriceError("The sales tax rate %s%% is out of range (0 to 20)." % pct_text(pct))
    is_int = lambda v: isinstance(v, int) and not isinstance(v, bool)
    lines, taxable, first = [], 0, None
    for n, it in enumerate(items, 1):
        if not isinstance(it, dict):
            raise PriceError("Line %d of the invoice can't be read." % n)
        d, q, c, t = it.get("d"), it.get("q"), it.get("c"), it.get("t")
        d = d.strip() if isinstance(d, str) else d
        if not isinstance(d, str) or not d:
            raise PriceError("Line %d has no description." % n)
        if len(d) > CUSTOM_MAX_DESC:
            raise PriceError("Line %d's description is longer than %d characters." % (n, CUSTOM_MAX_DESC))
        if not is_int(q) or not 1 <= q <= CUSTOM_MAX_QTY:
            raise PriceError("Line %d (%s): quantity must be a whole number from 1 to %d." % (n, d, CUSTOM_MAX_QTY))
        if not is_int(c) or not 1 <= c <= CUSTOM_MAX_CENTS:
            raise PriceError("Line %d (%s): the price must be from $0.01 to %s." % (n, d, money(CUSTOM_MAX_CENTS)))
        if not isinstance(t, bool):
            raise PriceError("Line %d (%s): taxable must be yes or no." % (n, d))
        first = first or d
        lines.append((d if q == 1 else "%s × %d" % (d, q), q * c))
        if t:
            taxable += q * c
    subtotal = sum(c for _, c in lines)
    if taxable:
        num = int(round(pct * 10000))          # the rate in 1/10000 of a percent, exact for 7.0, 7.5, 6.625...
        lines.append((SALES_TAX_LABEL % pct_text(pct), _half_up(taxable * num, 100 * 10000)))
        subtotal = sum(c for _, c in lines)
    if CARD_FEE_PCT and card_fee:
        lines.append(("Card processing fee, %d%%" % CARD_FEE_PCT, _half_up(subtotal * CARD_FEE_PCT, 100)))
    return {"lines": lines, "start": dt.date.today(), "summary": "%s: %s" % (CUSTOM_SUMMARY, first),
            "total": sum(c for _, c in lines), "items": len(items), "first": first}


def selftest():
    D = dt.date
    ok = lambda r, g=None: price_request(r, g)
    ADULT = "2020-05-01"
    b = ok({"service": "Boarding", "arrive": "2026-12-19", "depart": "2027-01-02", "dogSize": "From50to75",
            "birthDate": ADULT, "dogName": "Max", "discount": "Military"})
    assert b["lines"][0][1] == 14 * 5000 and b["lines"][1][1] == -14000
    assert b["lines"][2][1] == int(round(56000 * 0.03)) and b["total"] == 56000 + 1680, b
    assert b["start"] == D(2026, 12, 19)
    for size, cents in (("Under25", 4000), ("From25to50", 4500), ("From50to75", 5000),
                        ("From75to100", 5500), ("Over100", 6000)):
        assert ok({"service": "Boarding", "arrive": "2026-10-01", "depart": "2026-10-03", "dogSize": size,
                   "birthDate": ADULT})["lines"][0][1] == 2 * cents, size
    # puppies, by age on the drop-off date (2026-10-01)
    assert age_months(D(2026, 4, 2), D(2026, 10, 1)) == 5 and age_months(D(2026, 4, 1), D(2026, 10, 1)) == 6
    pup = ok({"service": "Boarding", "arrive": "2026-10-01", "depart": "2026-10-04", "dogSize": "Under25",
              "birthDate": "2026-06-10", "dogName": "Pip"})
    assert [c for _, c in pup["lines"]] == [12000, 7500, 585], pup
    assert pup["lines"][1][0] == "Puppy, under 6 months at drop-off, 3 nights × $25", pup["lines"][1]
    teen = ok({"service": "Boarding", "arrive": "2026-10-01", "depart": "2026-10-03", "dogSize": "From25to50",
               "birthDate": "2026-04-01"})
    assert [c for _, c in teen["lines"][:2]] == [9000, 3000], teen            # exactly 6 months: +$15
    assert len(ok({"service": "Boarding", "arrive": "2026-10-01", "depart": "2026-10-02", "dogSize": "Over100",
                   "birthDate": "2025-10-01"})["lines"]) == 2                     # exactly 12 months: no upcharge
    assert boarding_night("Under25", "2026-06-10", D(2026, 10, 1)) == 6500
    assert boarding_night("Over40", ADULT, D(2026, 10, 1)) is None and boarding_night("Under25", None, D(2026, 10, 1)) is None
    for bad_size in ("Over40", "Under40", ""):
        try:
            ok({"service": "Boarding", "arrive": "2026-10-01", "depart": "2026-10-03", "dogSize": bad_size,
                "birthDate": ADULT})
            raise AssertionError("old size should fail: %r" % bad_size)
        except PriceError as e:
            assert "vet records" in str(e), e
    try:
        ok({"service": "Boarding", "arrive": "2026-10-01", "depart": "2026-10-03", "dogSize": "Under25"})
        raise AssertionError("missing birth date should fail")
    except PriceError as e:
        assert "birth date" in str(e), e
    assert ok({"service": "Boarding", "arrive": "2026-10-01", "depart": "2026-10-03", "dogSize": "Over40",
               "price": "90"})["lines"][0][1] == 9000                           # override needs neither
    bt = ok({"service": "BoardAndTrain", "arrive": "2026-10-05", "depart": "2026-11-02"})
    assert bt["lines"][0][1] == 4 * 100000, bt
    assert ok({"service": "BoardAndTrain", "arrive": "2026-10-05", "depart": "2026-10-19"})["lines"][0][1] == 240000
    # 2026-10-09 Fri, 10-10 Sat
    dy = ok({"service": "DayStayAndTrain", "arrive": "2026-10-09", "depart": "2026-10-10"})
    assert [c for _, c in dy["lines"][:2]] == [17500, 22500], dy
    pl = ok({"service": "PrivateLesson", "lessonStart": "2026-10-01 14:00:00", "lessonPackage": "Pack5"})
    assert pl["lines"][0][1] == 45000
    gc = ok({"service": "GroupClass"}, {"name": "Basic Nov", "classType": "OffLeashBasic",
                                        "dateStart": "2026-11-03 18:00:00"})
    assert gc["lines"][0][1] == 40000
    ov = ok({"service": "BoardAndTrain", "arrive": "2026-10-05", "depart": "2026-10-15", "price": "1500"})
    assert ov["lines"][0][1] == 150000
    for bad in ({"service": "Boarding", "arrive": "2026-10-01", "depart": "2026-10-03"},
                {"service": "BoardAndTrain", "arrive": "2026-10-05", "depart": "2026-10-15"},
                {"service": "DayStayAndTrain", "arrive": "2026-10-11"},
                {"service": "PrivateLesson"}, {"service": "GroupClass"}, {}):
        try:
            price_request(bad)
        except PriceError:
            continue
        raise AssertionError("should have failed: %r" % bad)
    two = price_dogs({"service": "Boarding", "arrive": "2026-10-01", "depart": "2026-10-04", "discount": "Senior"},
                     [{"name": "Max", "dogSize": "From50to75", "birthDate": ADULT},
                      {"name": "Bella", "dogSize": "Under25", "birthDate": "2026-06-10"}])
    assert [c for _, c in two["lines"]] == [15000, 12000, 7500, -6900, 828], two
    assert two["lines"][2][0].startswith("Bella: Puppy"), two
    assert two["summary"].startswith("Boarding for Max & Bella") and two["lines"][0][0].startswith("Max: "), two
    try:
        price_dogs({"service": "Boarding", "arrive": "2026-10-01", "depart": "2026-10-04"},
                   [{"name": "Max", "dogSize": "From50to75", "birthDate": ADULT}, {"name": "Bella", "birthDate": ADULT}])
        raise AssertionError("missing size should fail")
    except PriceError as e:
        assert str(e).startswith("Bella:"), e
    one = price_dogs({"service": "Boarding", "arrive": "2026-10-01", "depart": "2026-10-04"},
                     [{"name": "Max", "dogSize": "Under25", "birthDate": ADULT}])
    assert one["total"] == 12000 + 360 and one["summary"].startswith("Boarding for Max"), one
    gc = price_dogs({"service": "GroupClass"}, [{"name": "A"}, {"name": "B"}],
                    {"name": "Basic Nov", "classType": "BasicObedience", "dateStart": "2026-11-03 18:00:00"})
    assert [c for _, c in gc["lines"]][:2] == [20000, 20000], gc
    # custom invoices: the spec's e-collar, then a taxable/non-taxable mix with quantities
    ec = price_custom({"invoiceLines": '[{"d": "Dogtra 280C e-collar", "q": 1, "c": 18999, "t": true}]'})
    assert ec["lines"] == [("Dogtra 280C e-collar", 18999), ("Florida sales tax, 7% (Pinellas)", 1330),
                           ("Card processing fee, 3%", 610)] and ec["total"] == 20939, ec
    assert price_custom({"invoiceLines": [{"d": "Dogtra 280C e-collar", "q": 1, "c": 18999, "t": True}]},
                        card_fee=False)["total"] == 20329
    assert ec["summary"] == "Invoice from Pack Leader K9: Dogtra 280C e-collar" and ec["start"] == D.today(), ec
    mix = price_custom({"invoiceLines": [{"d": "Training treats", "q": 3, "c": 899, "t": True},
                                         {"d": "Collar fitting", "q": 1, "c": 2500, "t": False}]}, tax_pct=7.5)
    assert mix["lines"] == [("Training treats × 3", 2697), ("Collar fitting", 2500),
                            ("Florida sales tax, 7.5% (Pinellas)", 202), ("Card processing fee, 3%", 162)], mix
    assert mix["total"] == 5561 and mix["items"] == 2 and mix["first"] == "Training treats", mix
    nt = price_custom({"invoiceLines": [{"d": "Lesson add-on", "q": 2, "c": 1000, "t": False}]}, card_fee=False)
    assert nt["lines"] == [("Lesson add-on × 2", 2000)] and nt["total"] == 2000, nt     # no tax line
    assert _half_up(5, 10) == 1 and _half_up(4, 10) == 0 and pct_text(7.0) == "7" and pct_text(6.625) == "6.625"
    for bad, words in (("", "no lines"), ("[", "valid JSON"), ("[]", "list of 1"), ('{"d": 1}', "list of 1"),
                       ([{"d": "x", "q": 1, "c": 1, "t": True}] * 21, "at most 20"),
                       ([{"d": " ", "q": 1, "c": 100, "t": True}], "no description"),
                       ([{"d": "x" * 121, "q": 1, "c": 100, "t": True}], "120 characters"),
                       ([{"d": "Leash", "q": 0, "c": 100, "t": True}], "quantity"),
                       ([{"d": "Leash", "q": 1.5, "c": 100, "t": True}], "quantity"),
                       ([{"d": "Leash", "q": True, "c": 100, "t": True}], "quantity"),
                       ([{"d": "Leash", "q": 100, "c": 100, "t": True}], "quantity"),
                       ([{"d": "Leash", "q": 1, "c": 0, "t": True}], "price"),
                       ([{"d": "Leash", "q": 1, "c": 1000001, "t": True}], "price"),
                       ([{"d": "Leash", "q": 1, "c": "100", "t": True}], "price"),
                       ([{"d": "Leash", "q": 1, "c": 100}], "taxable"),
                       (["Leash"], "can't be read")):
        try:
            price_custom({"invoiceLines": bad})
            raise AssertionError("should have failed: %r" % (bad,))
        except PriceError as e:
            assert words in str(e), (bad, e)
    try:
        price_custom({"invoiceLines": [{"d": "x", "q": 1, "c": 1, "t": True}]}, tax_pct=70)
        raise AssertionError("tax rate 70 should fail")
    except PriceError as e:
        assert "out of range" in str(e), e
    page = rates_html()
    for want in ("$100", "$285", "$450", "$850", "$1,200", "$1,000", "$175", "$225",
                 "Under 25 lbs, per night</td><td>$40", "25–50 lbs, per night</td><td>$45",
                 "50–75 lbs, per night</td><td>$50", "75–100 lbs, per night</td><td>$55",
                 "100+ lbs, per night</td><td>$60", "Puppy under 6 months</td><td>+$25",
                 "Puppy 6–12 months</td><td>+$15",
                 "Off-Leash Basic (up to 5 dogs)", "$95 a session", "20% off", "3% processing"):
        assert want in page, want
    return "pricing ok"


if __name__ == "__main__":
    print(selftest())
