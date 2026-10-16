#!/usr/bin/env python3
"""One-time setup in the office book for packleader-office-flow.py. Run on fTower.

What it changes (dry run by default; --apply to do it):

  Lead (Requests)
    Status gets Approved and Declined. New fields she fills when deciding:
    Dog size, Discount, Price (override), Lesson start / minutes / package,
    Group class, Note to client. Fields the robot fills: Office robot,
    Payment, Invoice total, Payment due, Pay link, Paid at, Booking,
    Thanks sent, Decision sent, Stripe invoice. Two new panels on the
    request: "Decision" (top) and "Invoice and payment".
  Lead and Dog       Vet and behavior: vet clinic, address, phone, preferred
                     vet, preferred emergency vet, "My dog is good with"
                     checkboxes. The form asks; the robot copies them onto the dog.
  Meeting (Lessons)  Class type (sets a group class's price and size). No-show (tick
                     with Held when a client didn't come; the session is still used).
  Lesson packages    New record type (tab after Lessons; panel on the client
                     and the dog). One per 3/5/10-pack: lessons bought, done,
                     booked, left. Lessons link to it; hooks in the office book
                     recount it whenever a lesson changes. From
                     configs/espocrm/lesson-package/.
  Stay (Bookings)    Discount gets First responder.
  Shot records       Upload page for clients (link in the thank-you email):
                     entry point shotRecords + no-login route
                     POST /api/v1/PackLeader/shotRecords/:id, from configs/espocrm/.
                     Files go in Shot records on the request, then on the dog.
  New client form    Email becomes required; Dog size, the vet and behavior
                     questions, and "Anything else we should know" are added.
  Returning clients  Dog gets its care details (size, food, feeding,
                     medications, special care) so a returning client's page can
                     be pre-filled. Contact gets "Client link" (their private
                     sign-in link; clear it to reset). Request gets "Returning
                     client" and "What changed". Pages: ?entryPoint=client, and
                     no-login routes POST /api/v1/PackLeader/bookAgain/:id and
                     /PackLeader/clientLink ("Booked with us before?").
  Kennel board       ?entryPoint=kennelBoard (login required): dogs here now,
                     arriving in the next day, leaving today, with feeding,
                     medications, care, shots and contacts. Tab in the top bar.
  Kennel dash user   API user kennel-dash, role "Kennel dash (read only)": read
                     Bookings, Dogs, Requests, nothing else. Key ->
                     ~/espo-kennel-dash.key, for the console panel homelab-kennel.
  Outgoing email     EspoCRM sends through office@ on Proton (the same
                     ~/packleader-smtp.token as the robot), so a sign-in link
                     goes out at once.
  API user           office-flow with role "Office flow": read/edit
                     Requests, create/edit Contacts, Dogs, Bookings,
                     Lessons. Key -> ~/espo-office-flow.key (mode 600).

Merges into the existing custom metadata; never removes a field. Backs up
custom/ first to ~/espocrm-custom-backup-<time>.tgz. Aborts if a field it
wants to add already exists with a different type. Safe to re-run.

  packleader-office-flow-install.py            # show the plan
  packleader-office-flow-install.py --apply
"""
import argparse, base64, copy, json, os, subprocess, sys, time
import urllib.error, urllib.parse, urllib.request

from packleader_pricing import BOARDING_NIGHT, OLD_SIZE_LABEL, PUPPY_NIGHT, SIZE_LABEL, money

# PK9_* overrides point this at a throwaway copy of the office book for testing.
C = os.environ.get("PK9_CONTAINER", "espocrm")
ROOT = "custom/Espo/Custom/Resources/"
ESPO = os.environ.get("PK9_ESPO_URL", "http://127.0.0.1:8210/api/v1/")
ENV = os.environ.get("PK9_ESPO_ENV", "/opt/docker/espocrm/.env")
KEY_OUT = os.path.expanduser(os.environ.get("PK9_ESPO_KEY", "~/espo-office-flow.key"))
EXPECT_IMAGE = "espocrm/espocrm:10.0.9"   # 10.0.8 -> 10.0.9 on 2026-10-02: no migrations

# Dog size = weight tier from the vet records (2026-09-28). The old over/under 40 lbs
# values stay as options so dogs and bookings from before still show them.
SIZE_OPTIONS = [""] + list(SIZE_LABEL) + list(OLD_SIZE_LABEL)
SIZE_I18N = dict({"": ""}, **SIZE_LABEL, **OLD_SIZE_LABEL)
SIZE_TIP = ("Weight from the vet records. Boarding per night: %s. Puppies add %s."
            % (", ".join("%s %s" % (SIZE_LABEL[k], money(c)) for k, c in BOARDING_NIGHT.items()),
               " / ".join("+%s %s" % (money(c), label) for _, c, label in PUPPY_NIGHT)))
BIRTH_TIP = "Required. Boarding adds a puppy rate by age at drop-off (under 6 months, 6-12 months)."

LEAD_FIELDS = {
    "dogSize": {"type": "enum", "options": SIZE_OPTIONS},
    "birthDate": {"type": "date"},      # old one-dog form only; the booking pages put it on each dog
    # How this request is paid: Card = Stripe invoice (the default), Zelle = emailed Zelle details.
    "payMethod": {"type": "enum", "options": ["", "Card", "Zelle"]},
    "discount": {"type": "enum", "options": ["", "Military", "FirstResponder", "Senior", "Rescue"]},
    "price": {"type": "currency", "min": 0},
    "lessonStart": {"type": "datetime"},
    "lessonMinutes": {"type": "int", "default": 30, "min": 15, "max": 480},   # 30 since 2026-09-28
    "lessonPackage": {"type": "enum", "options": ["Single", "Pack3", "Pack5", "Pack10"], "default": "Single"},
    "groupClass": {"type": "link"},
    "clientNote": {"type": "text", "rows": 3},
    "flowNote": {"type": "text", "rows": 2},
    "ackSentAt": {"type": "datetime"},
    "decisionSentAt": {"type": "datetime"},
    "bookingUrl": {"type": "url"},
    "invoiceTotal": {"type": "currency"},
    "paymentDue": {"type": "datetime"},
    "invoiceUrl": {"type": "url"},
    "bankInvoiceUrl": {"type": "url"},   # the no-fee bank invoice (since 2026-10-12; the card one is invoiceUrl)
    "stripeInvoiceId": {"type": "varchar", "maxLength": 64},
    "paymentStatus": {"type": "enum", "options": ["", "Invoiced", "Paid", "PaidOther"],
                      "style": {"Invoiced": "warning", "Paid": "success", "PaidOther": "success"}},
    "paidAt": {"type": "datetime"},
}
# Where a private lesson happens (never at the facility). Same fields on the lesson (Meeting).
LESSON_LOCATION_FIELDS = {
    "lessonLocation": {"type": "enum", "options": ["", "ClientHome", "HomeDepot", "Park", "Other"]},
    "lessonAddress": {"type": "varchar", "maxLength": 255},
}
LEAD_FIELDS.update(LESSON_LOCATION_FIELDS)
for v in LEAD_FIELDS.values():
    v["isCustom"] = True
# Asked on the form, kept on the dog. Same definitions on Lead and Dog.
GOOD_WITH = ["MaleDogs", "FemaleDogs", "LargeDogs", "SmallDogs", "Puppies", "Cats", "Children",
             "NewPeople", "Unknown"]
VET_FIELDS = {
    "vetClinic": {"type": "varchar", "maxLength": 120},
    "vetName": {"type": "varchar", "maxLength": 120},
    "vetAddress": {"type": "varchar", "maxLength": 255},
    "vetPhone": {"type": "varchar", "maxLength": 40},
    "emergencyVet": {"type": "varchar", "maxLength": 160},
    "goodWith": {"type": "checklist", "options": GOOD_WITH},
}
VET_FIELDS["shotRecords"] = {"type": "attachmentMultiple", "sourceList": []}
for v in VET_FIELDS.values():
    v["isCustom"] = True
# Robot bookkeeping on the request, not shown in layouts.
LEAD_FIELDS_HIDDEN = {
    "uploadToken": {"type": "varchar", "maxLength": 64, "isCustom": True},
    "dogRecordId": {"type": "varchar", "maxLength": 24, "isCustom": True},
    "shotRecordsSynced": {"type": "text", "isCustom": True},
    "lessonPackageId": {"type": "varchar", "maxLength": 24, "isCustom": True},
}
PHP_FILES = {  # repo file -> path in the container
    "ShotRecords.php": "custom/Espo/Custom/EntryPoints/ShotRecords.php",
    "ShotRecordsUpload.php": "custom/Espo/Custom/Api/ShotRecordsUpload.php",
    "Booking.php": "custom/Espo/Custom/EntryPoints/Booking.php",   # EspoCRM serves class X at ?entryPoint=x
    "BookingSubmit.php": "custom/Espo/Custom/Api/BookingSubmit.php",
    "PackLeaderClient.php": "custom/Espo/Custom/PackLeader/Client.php",
    "Client.php": "custom/Espo/Custom/EntryPoints/Client.php",
    "BookAgainSubmit.php": "custom/Espo/Custom/Api/BookAgainSubmit.php",
    "ClientLinkRequest.php": "custom/Espo/Custom/Api/ClientLinkRequest.php",
    "KennelBoard.php": "custom/Espo/Custom/EntryPoints/KennelBoard.php",
    "LessonSchedule.php": "custom/Espo/Custom/EntryPoints/LessonSchedule.php",
}
# Top-bar tab for the kennel board, right after Bookings. Only users who can read Bookings see it.
BOARD_TAB = {"type": "url", "text": "Kennel board", "url": "?entryPoint=kennelBoard", "iconClass": "fas fa-paw",
             "color": None, "aclScope": "Stay", "onlyAdmin": False, "id": "pk9board"}
# Whole-file installs: configs/espocrm/<tree>/<path> -> <path> in the container.
# request-dog: RequestDog = one dog on a booking request (a request can have several).
# lesson-package: LessonPackage = a 3/5/10-pack of private lessons, its count hooks,
#   and the "+" handler that pre-fills a new lesson from the package.
# stay-care: a booking made by hand copies the dog's care details (form + save hook).
TREES = ("request-dog", "lesson-package", "stay-care")
LEAD_DOG_LINK = {"requestDogs": {"type": "hasMany", "entity": "RequestDog", "foreign": "request", "isCustom": True}}
# The request screen: the per-dog fields move off it into the Dogs panel.
SERVICE_PANEL = {"label": "Service", "rows": [
    [{"name": "dogName"}, {"name": "service"}],
    [{"name": "arrive"}, {"name": "depart"}],
    [{"name": "preferredTime"}, {"name": "dogSize"}],
    [{"name": "photoConsent"}, {"name": "agreementAccepted"}],
]}
CUSTOM_ROUTES = [{"route": "/PackLeader/shotRecords/:id", "method": "post",
                  "actionClassName": "Espo\\Custom\\Api\\ShotRecordsUpload", "noAuth": True},
                 {"route": "/PackLeader/booking", "method": "post",
                  "actionClassName": "Espo\\Custom\\Api\\BookingSubmit", "noAuth": True},
                 {"route": "/PackLeader/bookAgain/:id", "method": "post",
                  "actionClassName": "Espo\\Custom\\Api\\BookAgainSubmit", "noAuth": True},
                 {"route": "/PackLeader/clientLink", "method": "post",
                  "actionClassName": "Espo\\Custom\\Api\\ClientLinkRequest", "noAuth": True}]
CONFIGS = os.path.join(os.path.dirname(os.path.abspath(__file__)), "..", "configs", "espocrm")
VET_I18N = {
    "fields": {"vetClinic": "Vet clinic name", "vetName": "Preferred vet",
               "vetAddress": "Vet clinic address", "vetPhone": "Vet clinic phone",
               "emergencyVet": "Preferred emergency vet (if you have none, we use VEG Clearwater)",
               "goodWith": "My dog is good with (check all that apply)",
               "shotRecords": "Shot records"},
    "options": {"goodWith": {"MaleDogs": "Male dogs", "FemaleDogs": "Female dogs",
                             "LargeDogs": "Large dogs", "SmallDogs": "Small dogs", "Puppies": "Puppies",
                             "Cats": "Cats", "Children": "Children", "NewPeople": "New people",
                             "Unknown": "Not sure / unknown"}},
}
VET_PANEL = {"label": "Vet and behavior", "rows": [
    [{"name": "vetClinic"}, {"name": "vetName"}],
    [{"name": "vetAddress"}, {"name": "vetPhone"}],
    [{"name": "emergencyVet"}, False],
    [{"name": "goodWith", "fullWidth": True}],
    [{"name": "shotRecords", "fullWidth": True}],
]}
# Existing form fields, relabeled so the form asks for the detail she needs.
LEAD_RELABEL = {"feeding": "Feeding: food, amount, and times of day",
                "medications": "Medications, with dose and instructions (if any)",
                "description": "Anything else we should know"}
# Where the new questions go on the form: (field, after this field)
FORM_ADD = [("vetClinic", "emergencyPhone"), ("vetName", "vetClinic"), ("vetAddress", "vetName"),
            ("vetPhone", "vetAddress"), ("emergencyVet", "vetPhone"), ("goodWith", "temperament"),
            ("description", "specialCare"), ("birthDate", "age")]

# Returning clients. Dog: the care details the page pre-fills (newest answer wins).
DOG_CARE_FIELDS = {
    "dogSize": {"type": "enum", "options": SIZE_OPTIONS},
    "foodSource": {"type": "enum", "options": ["", "Owner", "Facility"]},
    "feeding": {"type": "text", "rows": 2},
    "medications": {"type": "text", "rows": 2},
    "specialCare": {"type": "text", "rows": 2},
}
# Contact: the permanent link she can copy or clear; the rest is sign-in bookkeeping.
CONTACT_FIELDS = {
    "clientLink": {"type": "url", "readOnly": False},   # editable: she clears it to reset the link
    "signinToken": {"type": "varchar", "maxLength": 64},
    "signinExpires": {"type": "datetime"},
    "signinCount": {"type": "int"},
    "signinWindow": {"type": "datetime"},
}
LEAD_RETURNING_FIELDS = {
    "returningClient": {"type": "bool"},
    "returningContactId": {"type": "varchar", "maxLength": 24},
    "returningChanges": {"type": "text", "rows": 2, "readOnly": True},
}
# Birth date: required on every dog (2026-09-28); boarding's puppy rate is priced from it.
DOG_BIRTH_FIELDS = {"birthDate": {"type": "date", "required": True}}
for group in (DOG_CARE_FIELDS, DOG_BIRTH_FIELDS, CONTACT_FIELDS, LEAD_RETURNING_FIELDS):
    for v in group.values():
        v["isCustom"] = True
CARE_PANEL = {"label": "Care", "rows": [
    [{"name": "dogSize"}, {"name": "foodSource"}],
    [{"name": "feeding", "fullWidth": True}],
    [{"name": "medications", "fullWidth": True}],
    [{"name": "specialCare", "fullWidth": True}],
]}
RETURNING_I18N = {
    "Dog": {"fields": {"dogSize": "Size", "foodSource": "Food", "feeding": "Feeding",
                       "medications": "Medications", "specialCare": "Special care", "birthDate": "Birth date"},
            "options": {"dogSize": SIZE_I18N,
                        "foodSource": {"": "", "Owner": "Owner brings food", "Facility": "Our food"}},
            "tooltips": {"feeding": "Updated from each approved booking. The client's latest answer wins.",
                         "dogSize": SIZE_TIP, "birthDate": BIRTH_TIP}},
    "Contact": {"fields": {"clientLink": "Client link"},
                "tooltips": {"clientLink": "Their private link to book again and see what's coming up. "
                                           "It is in every office email. Clear it to stop the old link working; "
                                           "a new one is made with the next email."}},
    "Lead": {"fields": {"returningClient": "Returning client", "returningChanges": "What changed"},
             "tooltips": {"returningChanges": "What the client changed since last time. "
                                               "Approving copies these changes onto the contact and dogs."}},
}
# Drop-off / pick-up blocks (Boarding, Board & train). Same values as Booking.php TIMES.
HANDOFF = {"type": "enum", "options": ["", "Morning", "Midday", "Evening"], "isCustom": True}
HANDOFF_FIELDS = {"dropOffTime": dict(HANDOFF), "pickUpTime": dict(HANDOFF)}
HANDOFF_I18N = {"fields": {"dropOffTime": "Drop-off time", "pickUpTime": "Pick-up time"},
                "options": {k: {"": "", "Morning": "Morning (8–10 AM)", "Midday": "Midday (12–2 PM)",
                                "Evening": "Evening (5–7 PM)"} for k in HANDOFF_FIELDS}}
# A request shows only the fields its service uses (2026-09-28: lesson fields on a boarding
# request were confusing). Office book dynamic logic: hidden, not cleared.
def _only(*services):
    c = ({"type": "equals", "attribute": "service", "value": services[0]} if len(services) == 1
         else {"type": "in", "attribute": "service", "value": list(services)})
    return {"visible": {"conditionGroup": [c]}}
LEAD_VISIBLE = {**{f: _only("PrivateLesson") for f in ("lessonStart", "lessonMinutes", "lessonLocation",
                                                       "lessonAddress", "lessonPackage")},
                "groupClass": _only("GroupClass"),
                "dogSize": _only("Boarding"),
                "dropOffTime": _only("Boarding", "BoardAndTrain"),
                "pickUpTime": _only("Boarding", "BoardAndTrain")}
SMTP_HOST, SMTP_PORT = "smtp.protonmail.ch", 587
SMTP_FILE = os.path.expanduser(os.environ.get("PK9_SMTP_FILE", "~/packleader-smtp.token"))

# "ApprovedZelle" is a one-shot choice: the robot records payMethod=Zelle and sets it back to
# "Approved", so everything downstream only ever sees "Approved" (2026-09-28).
LEAD_STATUS = ["New", "Assigned", "In Process", "Approved", "ApprovedZelle", "Declined", "Converted", "Recycled", "Dead"]
LEAD_LINKS = {"groupClass": {"type": "belongsTo", "entity": "Meeting", "foreign": "classRequests",
                             "isCustom": True}}
MEETING_FIELDS = {"duration": {"type": "duration", "default": 1800},   # lessons made by hand: 30 min (core: 1 h)
                  "classType": {"type": "enum", "isCustom": True, "options": [
    "", "BasicObedience", "AdvancedObedience", "OffLeashBasic", "OffLeashAdvanced"]},
                  "lessonPackage": {"type": "link", "isCustom": True},
                  # No-show (2026-10-13, owner OK): a no-show is Held (it uses the session up) + noShow,
                  # so the phone app can tell it from a lesson that happened. Package hooks only read status.
                  "noShow": {"type": "bool", "default": False, "isCustom": True},
                  **{k: dict(v, isCustom=True) for k, v in LESSON_LOCATION_FIELDS.items()}}
MEETING_LINKS = {"classRequests": {"type": "hasMany", "entity": "Lead", "foreign": "groupClass",
                                   "isCustom": True, "layoutRelationshipsDisabled": True},
                 "lessonPackage": {"type": "belongsTo", "entity": "LessonPackage", "foreign": "lessons",
                                   "isCustom": True}}
# The other side of LessonPackage.contact / .dog: a "Lesson packages" panel on each.
PACKAGE_LINK = {"lessonPackages": {"type": "hasMany", "entity": "LessonPackage", "isCustom": True}}
CONTACT_LINKS = {"lessonPackages": dict(PACKAGE_LINK["lessonPackages"], foreign="contact")}
DOG_LINKS = {"lessonPackages": dict(PACKAGE_LINK["lessonPackages"], foreign="dog")}
PACKAGE_PANEL = {"create": True, "select": False, "layout": "listSmall"}
# Contact menu item: the client's lesson schedule (times + locations) to print, copy or download.
CONTACT_SCHEDULE_ACTION = {"name": "lessonSchedule", "label": "Lesson schedule",
                           "handler": "custom:handlers/contact-lesson-schedule", "actionFunction": "open"}
CONTACT_CLIENTDEFS = {"relationshipPanels": {"lessonPackages": dict(
    PACKAGE_PANEL, createAttributeMap={"id": "contactId", "name": "contactName"})}}
DOG_CLIENTDEFS = {"relationshipPanels": {"lessonPackages": dict(
    PACKAGE_PANEL, createAttributeMap={"id": "dogId", "name": "dogName",
                                       "contactId": "contactId", "contactName": "contactName"})}}
STAY_CLIENTDEFS = {"dynamicHandler": "custom:handlers/stay-dog-care"}
MEETING_CLIENTDEFS = {"dynamicHandler": "custom:handlers/meeting-location"}
STAY_DISCOUNT = ["", "Military", "FirstResponder", "Senior", "Rescue"]

LEAD_I18N = {
    "fields": {
        "dogSize": "Dog size", "discount": "Discount", "price": "Price (override)",
        "lessonStart": "Lesson start", "lessonMinutes": "Lesson minutes",
        "lessonPackage": "Lesson package", "groupClass": "Group class",
        "clientNote": "Note to client", "flowNote": "Office robot",
        "ackSentAt": "Thanks sent", "decisionSentAt": "Decision sent",
        "bookingUrl": "Booking", "invoiceTotal": "Invoice total", "paymentDue": "Payment due",
        "invoiceUrl": "Card pay link", "bankInvoiceUrl": "Bank pay link (no fee)",
        "stripeInvoiceId": "Stripe invoice", "paymentStatus": "Payment",
        "paidAt": "Paid at", "lessonLocation": "Lesson location", "lessonAddress": "Lesson address",
        "birthDate": "Birth date", "payMethod": "Payment method",
    },
    "links": {"groupClass": "Group class"},
    "options": {
        "status": {"Approved": "Approved", "ApprovedZelle": "Approved (paying through Zelle)", "Declined": "Declined"},
        "payMethod": {"": "", "Card": "Card (Stripe)", "Zelle": "Zelle"},
        "dogSize": SIZE_I18N,
        "discount": {"": "None", "Military": "Military", "FirstResponder": "First responder",
                     "Senior": "Senior (65+)", "Rescue": "Rescue"},
        "lessonPackage": {"Single": "Single session", "Pack3": "3-pack", "Pack5": "5-pack",
                          "Pack10": "10-pack"},
        "paymentStatus": {"": "", "Invoiced": "Invoiced", "Paid": "Paid (Stripe)",
                          "PaidOther": "Paid another way"},
        "lessonLocation": {"": "", "ClientHome": "Client's home", "HomeDepot": "Home Depot", "Park": "Park", "Other": "Other"},
    },
    "tooltips": {
        "price": "Leave empty to use the price list. Type a total only to override it.",
        "clientNote": "Goes into the approval or decline email, word for word.",
        "flowNote": "Written by the office robot when it can't finish a request.",
        "paymentStatus": "Set to 'Paid another way' when they pay by Zelle, Venmo or cash.",
        "payMethod": "Set by the office robot. 'Approved (paying through Zelle)' in Status makes it Zelle: "
                     "the approval email gives the Zelle details instead of a card link, with no card fee.",
        "dogSize": SIZE_TIP,
        "groupClass": "For a group class request: the class this dog joins.",
        "lessonAddress": "The address or place: their street address, which Home Depot, which park. Goes in the booked email.",
    },
}
MEETING_I18N = {"fields": {"classType": "Class type", "lessonPackage": "Lesson package", "noShow": "No-show",
                           "lessonLocation": "Location", "lessonAddress": "Address"},
                "links": {"classRequests": "Requests", "lessonPackage": "Lesson package"},
                "tooltips": {"lessonPackage": "The 3/5/10-pack this lesson comes out of. "
                                              "The package counts it as soon as it is booked."},
                "options": {"lessonLocation": {"": "", "ClientHome": "Client's home", "HomeDepot": "Home Depot", "Park": "Park", "Other": "Other"},
                            "classType": {"": "", "BasicObedience": "Basic Obedience",
                                          "AdvancedObedience": "Advanced Obedience",
                                          "OffLeashBasic": "Off-Leash Basic",
                                          "OffLeashAdvanced": "Off-Leash Advanced"}}}
PACKAGES_I18N = {"fields": {"lessonPackages": "Lesson packages"}, "links": {"lessonPackages": "Lesson packages"}}
STAY_I18N = {"options": {"discount": {"FirstResponder": "First responder"}}}

DECISION_PANEL = {"label": "Decision", "rows": [
    [{"name": "status"}, {"name": "discount"}],
    [{"name": "dogSize"}, {"name": "price"}],
    [{"name": "lessonStart"}, {"name": "lessonMinutes"}],
    [{"name": "lessonLocation"}, {"name": "lessonAddress"}],
    [{"name": "lessonPackage"}, {"name": "groupClass"}],
    [{"name": "clientNote", "fullWidth": True}],
    [{"name": "flowNote", "fullWidth": True}],
]}
PAYMENT_PANEL = {"label": "Invoice and payment", "rows": [
    [{"name": "payMethod"}, False],
    [{"name": "paymentStatus"}, {"name": "invoiceTotal"}],
    [{"name": "paymentDue"}, {"name": "paidAt"}],
    [{"name": "invoiceUrl"}, {"name": "bookingUrl"}],
    [{"name": "bankInvoiceUrl"}, False],
    [{"name": "ackSentAt"}, {"name": "decisionSentAt"}],
    [{"name": "stripeInvoiceId"}, False],
]}

ROLE = {"name": "Office flow", "assignmentPermission": "all", "userPermission": "no",
        "data": {"Lead": {"create": "no", "read": "all", "edit": "all", "delete": "no", "stream": "all"},
                 "Contact": {"create": "yes", "read": "all", "edit": "all", "delete": "no", "stream": "no"},
                 "Dog": {"create": "yes", "read": "all", "edit": "all", "delete": "no"},
                 "Stay": {"create": "yes", "read": "all", "edit": "all", "delete": "no"},
                 "Meeting": {"create": "yes", "read": "all", "edit": "all", "delete": "no", "stream": "no"},
                 "RequestDog": {"create": "yes", "read": "all", "edit": "all", "delete": "no"},
                 "LessonPackage": {"create": "yes", "read": "all", "edit": "all", "delete": "no"},
                 # assigning her to a booking or lesson needs read access to the user record
                 "User": {"read": "all", "edit": "no"}},
        "fieldData": {}}


# The fTower console's kennel panel (c/src/homelab-kennel.c): look, never touch.
DASH_ROLE = {"name": "Kennel dash (read only)", "assignmentPermission": "no", "userPermission": "no",
             "data": {"Stay": {"create": "no", "read": "all", "edit": "no", "delete": "no"},
                      "Dog": {"create": "no", "read": "all", "edit": "no", "delete": "no"},
                      "Lead": {"create": "no", "read": "all", "edit": "no", "delete": "no", "stream": "no"}},
             "fieldData": {}}
DASH_KEY_OUT = os.path.expanduser(os.environ.get("PK9_DASH_KEY", "~/espo-kennel-dash.key"))


def sh(*cmd, stdin=None, check=True):
    r = subprocess.run(cmd, input=stdin, capture_output=True, text=True)
    if check and r.returncode:
        sys.exit("FAILED %s\n%s" % (" ".join(cmd), r.stderr))
    return r.stdout


def cread(path):
    out = subprocess.run(["docker", "exec", C, "cat", path], capture_output=True, text=True)
    return json.loads(out.stdout) if out.returncode == 0 and out.stdout.strip() else {}


def cwrite(path, data, owner):
    sh("docker", "exec", "-i", "-u", owner, C, "sh", "-c",
       'mkdir -p "$(dirname "$1")" && cat > "$1"', "-", path,
       stdin=json.dumps(data, indent=4, ensure_ascii=False) + "\n")


def merge(dst, src):
    """Recursive dict merge; src wins for scalars and lists."""
    for k, v in src.items():
        if isinstance(v, dict) and isinstance(dst.get(k), dict):
            merge(dst[k], v)
        else:
            dst[k] = copy.deepcopy(v)
    return dst


def admin_auth():
    env = dict(l.strip().split("=", 1) for l in open(ENV) if "=" in l and not l.startswith("#"))
    pw = env["ADMIN_PASSWORD"].strip().strip('"').strip("'")
    return {"Espo-Authorization": base64.b64encode(("admin:" + pw).encode()).decode(),
            "Content-Type": "application/json"}


def api(method, path, hdr, body=None, params=None):
    url = ESPO + path + ("?" + urllib.parse.urlencode(params, doseq=True) if params else "")
    r = urllib.request.Request(url, method=method, headers=hdr,
                               data=json.dumps(body).encode() if body is not None else None)
    try:
        with urllib.request.urlopen(r, timeout=60) as x:
            raw = x.read()
    except urllib.error.HTTPError as e:
        sys.exit("API %s %s -> %s %s" % (method, path, e.code,
                                         e.headers.get("X-Status-Reason") or e.read()[:300]))
    return json.loads(raw) if raw.strip() else None


def main():
    ap = argparse.ArgumentParser(description=__doc__.split("\n")[0])
    ap.add_argument("--apply", action="store_true")
    apply = ap.parse_args().apply
    say = lambda s: print(("" if apply else "[dry run] ") + s)

    # ---- preflight: abort if reality drifted from what this was written against
    if subprocess.run(["docker", "inspect", C], capture_output=True).returncode:
        sys.exit("No %s container here. Run this on fTower:\n  ssh <user>@<host>\n"
                 "  git -C ~/homelab pull && python3 ~/homelab/scripts/packleader-office-flow-install.py" % C)
    img = sh("docker", "inspect", "-f", "{{.Config.Image}}", C).strip()
    if img != EXPECT_IMAGE:
        sys.exit("espocrm image is %s, this was written for %s. Re-check before running." % (img, EXPECT_IMAGE))
    owner = sh("docker", "exec", C, "stat", "-c", "%U", ROOT + "metadata/entityDefs/Lead.json").strip()
    lead = cread(ROOT + "metadata/entityDefs/Lead.json")
    meeting = cread(ROOT + "metadata/entityDefs/Meeting.json")
    stay = cread(ROOT + "metadata/entityDefs/Stay.json")
    dog = cread(ROOT + "metadata/entityDefs/Dog.json")
    contact = cread(ROOT + "metadata/entityDefs/Contact.json")
    if not lead.get("fields", {}).get("dogName") or not stay.get("fields") or not dog.get("fields"):
        sys.exit("Lead/Stay custom metadata is not what this was written for. Stopping.")
    for ent, have, want in (("Lead", lead, dict(LEAD_FIELDS, **VET_FIELDS, **LEAD_FIELDS_HIDDEN, **LEAD_RETURNING_FIELDS,
                                                 **HANDOFF_FIELDS)), ("Stay", stay, HANDOFF_FIELDS),
                            ("Meeting", meeting, MEETING_FIELDS), ("Dog", dog, dict(VET_FIELDS, **DOG_CARE_FIELDS, **DOG_BIRTH_FIELDS)),
                            ("Contact", contact, CONTACT_FIELDS)):
        for f, d in want.items():
            old = have.get("fields", {}).get(f)
            if old and old.get("type") != d["type"]:
                sys.exit("%s.%s already exists as %s (want %s). Stopping." % (ent, f, old.get("type"), d["type"]))
    hdr = admin_auth()
    api("GET", "App/user", hdr)   # admin login works
    print("preflight ok: %s, files owned by %s, admin login ok" % (img, owner))

    # ---- metadata
    new_lead = merge(copy.deepcopy(lead), {"fields": dict(LEAD_FIELDS, **VET_FIELDS, **LEAD_FIELDS_HIDDEN, **LEAD_RETURNING_FIELDS,
                                                          **HANDOFF_FIELDS),
                                           "links": dict(LEAD_LINKS, **LEAD_DOG_LINK)})
    new_dog = merge(copy.deepcopy(dog), {"fields": dict(VET_FIELDS, **DOG_CARE_FIELDS, **DOG_BIRTH_FIELDS),
                                         "links": DOG_LINKS})
    new_contact = merge(copy.deepcopy(contact), {"fields": CONTACT_FIELDS, "links": CONTACT_LINKS})
    if "lessonPackages" not in contact.get("links", {}):
        say("Contact and Dog: add the Lesson packages panel; Meeting: add Lesson package")
    cadded = [f for f in CONTACT_FIELDS if f not in contact.get("fields", {})]
    say("Contact: add %d fields (%s)" % (len(cadded), ", ".join(cadded) or "none"))
    radded = [f for f in LEAD_RETURNING_FIELDS if f not in lead.get("fields", {})]
    say("Lead: add %d returning-client fields (%s)" % (len(radded), ", ".join(radded) or "none"))
    new_lead["fields"]["status"] = merge(new_lead["fields"].get("status", {}), {"options": LEAD_STATUS,
                                         "style": {"Approved": "success", "ApprovedZelle": "success", "Declined": "danger"}})
    new_meeting = merge(copy.deepcopy(meeting), {"fields": MEETING_FIELDS, "links": MEETING_LINKS})
    new_stay = merge(copy.deepcopy(stay), {"fields": HANDOFF_FIELDS})
    hadded = [f for f in HANDOFF_FIELDS if f not in stay.get("fields", {})]
    say("Lead and Stay: add %s" % (", ".join(hadded) or "none (drop-off/pick-up times exist)"))
    new_stay["fields"]["discount"]["options"] = STAY_DISCOUNT
    added = [f for f in dict(LEAD_FIELDS, **VET_FIELDS, **LEAD_FIELDS_HIDDEN) if f not in lead.get("fields", {})]
    say("Lead: add %d fields (%s), status options -> %s" % (len(added), ", ".join(added) or "none", LEAD_STATUS))
    say("Meeting: add classType; Stay: discount options -> %s" % STAY_DISCOUNT)
    dadded = [f for f in dict(VET_FIELDS, **DOG_CARE_FIELDS, **DOG_BIRTH_FIELDS) if f not in dog.get("fields", {})]
    say("Dog: add %d fields (%s)" % (len(dadded), ", ".join(dadded) or "none"))

    lead_i18n = merge(copy.deepcopy(LEAD_I18N), VET_I18N)
    lead_i18n["fields"].update(LEAD_RELABEL)
    lead_i18n = merge(lead_i18n, {"links": {"requestDogs": "Dogs"}, "fields": {"requestDogs": "Dogs"}})
    lead_i18n = merge(lead_i18n, RETURNING_I18N["Lead"])
    lead_i18n = merge(lead_i18n, HANDOFF_I18N)
    i18n = {n: merge(cread(ROOT + "i18n/en_US/%s.json" % n), d)
            for n, d in (("Lead", lead_i18n), ("Meeting", MEETING_I18N), ("Stay", merge(copy.deepcopy(STAY_I18N), HANDOFF_I18N)),
                         ("Dog", merge(copy.deepcopy(VET_I18N), RETURNING_I18N["Dog"])),
                         ("Contact", merge(copy.deepcopy(RETURNING_I18N["Contact"]), PACKAGES_I18N)),
                         ("Global", {"scopeNames": {"RequestDog": "Dog on request",
                                                    "LessonPackage": "Lesson package"},
                                     "scopeNamesPlural": {"RequestDog": "Dogs on requests",
                                                          "LessonPackage": "Lesson packages"}}))}
    i18n["Dog"] = merge(i18n["Dog"], PACKAGES_I18N)

    # ---- layouts
    det = cread(ROOT + "layouts/Lead/detail.json")
    if not any(p.get("label") == "Decision" for p in det):
        for p in det:   # status moves to the Decision panel
            p["rows"] = [[c for c in row if not (c and c.get("name") == "status")] or [False, False]
                         for row in p["rows"]]
            p["rows"] = [row + [False] * (2 - len(row)) if len(row) < 2 else row for row in p["rows"]]
            p["rows"] = [row for row in p["rows"] if any(row)]
        det = [DECISION_PANEL] + det + [PAYMENT_PANEL]
        say("Lead detail layout: add Decision (top) and Invoice and payment panels")
    for p in det:   # the panel predates payMethod (2026-09-28): add it as the panel's first row
        if p.get("label") == PAYMENT_PANEL["label"] and "payMethod" not in json.dumps(p):
            p["rows"].insert(0, [{"name": "payMethod"}, False])
            say("Lead detail layout: add Payment method to Invoice and payment")
    if not any(p.get("label") in (VET_PANEL["label"], "Vet") for p in det):
        at = next((i + 1 for i, p in enumerate(det) if p.get("label") in ("Dog and service", "Service")), len(det))
        det.insert(at, copy.deepcopy(VET_PANEL))
        say("Lead detail layout: add Vet and behavior panel")
    ddet = cread(ROOT + "layouts/Dog/detail.json")
    for name, lay in (("Dog", ddet),):
        for p in lay or []:
            if p.get("label") == VET_PANEL["label"] and "shotRecords" not in json.dumps(p):
                p["rows"].append([{"name": "shotRecords", "fullWidth": True}])
                say("%s detail layout: add Shot records" % name)
    if ddet and not any(p.get("label") == VET_PANEL["label"] for p in ddet):
        ddet.append(copy.deepcopy(VET_PANEL))
        say("Dog detail layout: add Vet and behavior panel")
    for p in det:   # per-dog fields leave the request screen; they live on each dog now
        if p.get("label") == "Dog and service":
            p.clear(); p.update(copy.deepcopy(SERVICE_PANEL))
            say("Lead detail layout: 'Dog and service' -> 'Service' (dog details move to the Dogs panel)")
        if p.get("label") == VET_PANEL["label"]:
            keep = [row for row in p["rows"] if not any(c and c.get("name") in ("goodWith", "shotRecords") for c in row)]
            if keep != p["rows"]:
                p["label"] = "Vet"; p["rows"] = keep
                say("Lead detail layout: vet panel keeps only the household vet")
    if ddet and not any(p.get("label") == CARE_PANEL["label"] for p in ddet):
        ddet.insert(1, copy.deepcopy(CARE_PANEL))
        say("Dog detail layout: add Care panel")
    if ddet and "birthDate" not in json.dumps(ddet):
        where = next(((p, i) for p in ddet for i, row in enumerate(p["rows"])
                      if any(c and c.get("name") == "age" for c in row)), (ddet[0], 0))
        where[0]["rows"].insert(where[1] + 1, [{"name": "birthDate"}, False])
        say("Dog detail layout: add Birth date under Age")
    for p in det:
        if p.get("label") == "Decision" and "returningChanges" not in json.dumps(p):
            p["rows"].insert(1, [{"name": "returningClient"}, False])
            p["rows"].insert(2, [{"name": "returningChanges", "fullWidth": True}])
            say("Lead detail layout: Returning client + What changed in the Decision panel")
    sdet = cread(ROOT + "layouts/Stay/detail.json")
    for name, lay in (("Lead", det), ("Stay", sdet)):
        for p in lay or []:
            rows = p.get("rows", [])
            at = next((i for i, row in enumerate(rows) if any(c and c.get("name") == "arrive" for c in row)), None)
            if at is not None and "dropOffTime" not in json.dumps(lay):
                rows.insert(at + 1, [{"name": "dropOffTime"}, {"name": "pickUpTime"}])
                say("%s detail layout: drop-off and pick-up times under the dates" % name)
    cdet = cread(ROOT + "layouts/Contact/detail.json")
    if cdet and "clientLink" not in json.dumps(cdet):
        cdet[0]["rows"].append([{"name": "clientLink", "fullWidth": True}])
        say("Contact detail layout: add Client link")
    crel = cread(ROOT + "layouts/Contact/relationships.json")
    if crel and "lessonPackages" not in crel:
        crel.insert(crel.index("dogs") + 1 if "dogs" in crel else 0, "lessonPackages")
        say("Contact: add the Lesson packages panel under Dogs")
    ccd = merge(cread(ROOT + "metadata/clientDefs/Contact.json"), CONTACT_CLIENTDEFS)
    ccd["detailActionList"] = [a for a in ccd.get("detailActionList") or []
                               if a.get("name") != "lessonSchedule"] + [CONTACT_SCHEDULE_ACTION]
    dcd = merge(cread(ROOT + "metadata/clientDefs/Dog.json"), DOG_CLIENTDEFS)
    scd = merge(cread(ROOT + "metadata/clientDefs/Stay.json"), STAY_CLIENTDEFS)
    mcd = merge(cread(ROOT + "metadata/clientDefs/Meeting.json") or {}, MEETING_CLIENTDEFS)
    rel = cread(ROOT + "layouts/Lead/relationships.json") or ["targetLists"]
    if "requestDogs" not in rel:
        rel = ["requestDogs"] + rel
        say("Lead: add the Dogs panel")
    lcd = merge(cread(ROOT + "metadata/clientDefs/Lead.json"),
                {"relationshipPanels": {"requestDogs": {"create": True, "select": False, "layout": "listSmall"}},
                 "dynamicLogic": {"fields": LEAD_VISIBLE}})
    lst = cread(ROOT + "layouts/Lead/list.json")
    if lst and not any(c.get("name") == "paymentStatus" for c in lst):
        lst.append({"name": "paymentStatus", "width": 12})
        say("Lead list layout: add Payment column")
    if lst and not any(c.get("name") == "returningClient" for c in lst):
        lst.insert(1, {"name": "returningClient", "width": 9})
        say("Lead list layout: add Returning client column")
    for p in det:
        if p.get("label") == "Decision" and "lessonLocation" not in json.dumps(p):
            at = next((i + 1 for i, row in enumerate(p["rows"])
                       if any(c and c.get("name") == "lessonStart" for c in row)), len(p["rows"]))
            p["rows"].insert(at, [{"name": "lessonLocation"}, {"name": "lessonAddress"}])
            say("Lead detail layout: Lesson location + address in the Decision panel")
    mdet = cread(ROOT + "layouts/Meeting/detail.json")
    if mdet and "classType" not in json.dumps(mdet):
        mdet[0]["rows"].append([{"name": "classType"}, False])
        say("Meeting detail layout: add Class type")
    if mdet and "noShow" not in json.dumps(mdet):
        rows = mdet[0]["rows"]
        at = next((i + 1 for i, row in enumerate(rows) if any(c and c.get("name") == "status" for c in row)), len(rows))
        rows.insert(at, [{"name": "noShow"}, False])
        say("Meeting detail layout: add No-show under Status")
    if mdet and "lessonPackage" not in json.dumps(mdet):
        rows = mdet[0]["rows"]
        at = next((i + 1 for i, row in enumerate(rows) if any(c and c.get("name") == "dog" for c in row)), len(rows))
        rows.insert(at, [{"name": "lessonPackage"}, False])
        say("Meeting detail layout: add Lesson package under Dog")
    if mdet and "lessonLocation" not in json.dumps(mdet):
        rows = mdet[0]["rows"]
        at = next((i + 1 for i, row in enumerate(rows) if any(c and c.get("name") == "dateStart" for c in row)), 1)
        rows.insert(at, [{"name": "lessonLocation"}, {"name": "lessonAddress"}])
        say("Meeting detail layout: add Location + Address under the start time")
    # The calendar's pop-up uses detailSmall: copy Espo's own and add the location.
    msmall = cread(ROOT + "layouts/Meeting/detailSmall.json") or json.loads(subprocess.run(
        ["docker", "exec", C, "cat", "application/Espo/Modules/Crm/Resources/layouts/Meeting/detailSmall.json"],
        capture_output=True, text=True).stdout or "null")
    if msmall and "lessonLocation" not in json.dumps(msmall):
        rows = msmall[0]["rows"]
        at = next((i + 1 for i, row in enumerate(rows) if any(c and c.get("name") == "dateStart" for c in row)), 1)
        rows.insert(at, [{"name": "lessonLocation"}, {"name": "lessonAddress"}])
        say("Meeting calendar pop-up: add Location + Address")
    else:
        msmall = None

    php_changes = {}
    for src, dst in PHP_FILES.items():
        body = open(os.path.join(CONFIGS, src)).read()
        live = subprocess.run(["docker", "exec", C, "cat", dst], capture_output=True, text=True).stdout
        if live != body:
            php_changes[dst] = body
            say("install %s" % dst)
    for tree, dirpath, names in ((t, dp, ns) for t in TREES for dp, _, ns in os.walk(os.path.join(CONFIGS, t))):
        for n in names:
            src = os.path.join(dirpath, n)
            dst = os.path.relpath(src, os.path.join(CONFIGS, tree))
            body = open(src).read()
            live = subprocess.run(["docker", "exec", C, "cat", dst], capture_output=True, text=True).stdout
            if live != body:
                php_changes[dst] = body
                say("install %s" % dst)
    routes = cread("custom/Espo/Custom/Resources/routes.json") or []
    if routes and not isinstance(routes, list):
        sys.exit("custom routes.json is not a list. Stopping.")
    want_routes = [r for r in routes if r.get("route") not in {c["route"] for c in CUSTOM_ROUTES}] + CUSTOM_ROUTES
    if want_routes != routes:
        say("custom routes: %s (no login)" % ", ".join("POST " + c["route"] for c in CUSTOM_ROUTES))

    if apply:
        ts = time.strftime("%Y%m%d-%H%M%S")
        bak = os.path.expanduser("~/espocrm-custom-backup-%s.tgz" % ts)
        with open(bak, "wb") as f:
            subprocess.run(["docker", "exec", C, "tar", "czf", "-", "custom"], stdout=f, check=True)
        os.chmod(bak, 0o600)
        print("backup: %s" % bak)
        cwrite(ROOT + "metadata/entityDefs/Lead.json", new_lead, owner)
        cwrite(ROOT + "metadata/entityDefs/Meeting.json", new_meeting, owner)
        cwrite(ROOT + "metadata/entityDefs/Stay.json", new_stay, owner)
        cwrite(ROOT + "metadata/entityDefs/Dog.json", new_dog, owner)
        cwrite(ROOT + "metadata/entityDefs/Contact.json", new_contact, owner)
        if cdet:
            cwrite(ROOT + "layouts/Contact/detail.json", cdet, owner)
        if crel:
            cwrite(ROOT + "layouts/Contact/relationships.json", crel, owner)
        cwrite(ROOT + "metadata/clientDefs/Contact.json", ccd, owner)
        cwrite(ROOT + "metadata/clientDefs/Dog.json", dcd, owner)
        cwrite(ROOT + "metadata/clientDefs/Stay.json", scd, owner)
        cwrite(ROOT + "metadata/clientDefs/Meeting.json", mcd, owner)
        if sdet:
            cwrite(ROOT + "layouts/Stay/detail.json", sdet, owner)
        for dst, body in php_changes.items():
            sh("docker", "exec", "-i", "-u", owner, C, "sh", "-c",
               'mkdir -p "$(dirname "$1")" && cat > "$1"', "-", dst, stdin=body)
        if want_routes != routes:
            cwrite("custom/Espo/Custom/Resources/routes.json", want_routes, owner)
        if ddet:
            cwrite(ROOT + "layouts/Dog/detail.json", ddet, owner)
        for n, d in i18n.items():
            cwrite(ROOT + "i18n/en_US/%s.json" % n, d, owner)
        cwrite(ROOT + "layouts/Lead/detail.json", det, owner)
        cwrite(ROOT + "layouts/Lead/relationships.json", rel, owner)
        cwrite(ROOT + "metadata/clientDefs/Lead.json", lcd, owner)
        if lst:
            cwrite(ROOT + "layouts/Lead/list.json", lst, owner)
        if mdet:
            cwrite(ROOT + "layouts/Meeting/detail.json", mdet, owner)
        if msmall:
            cwrite(ROOT + "layouts/Meeting/detailSmall.json", msmall, owner)
        print(sh("docker", "exec", "-u", owner, C, "bin/command", "rebuild").strip() or "rebuild ok")
        sh("docker", "exec", "-u", owner, C, "bin/command", "clear-cache")

    # ---- new client form
    for lc in api("GET", "LeadCapture", hdr, params={"maxSize": 50})["list"]:
        full = api("GET", "LeadCapture/" + lc["id"], hdr)
        fl, fp = list(full.get("fieldList") or []), dict(full.get("fieldParams") or {})
        want_fl = list(fl)
        if "dogSize" in want_fl:   # the office sets the weight tier from the vet records (2026-09-28)
            want_fl.remove("dogSize")
        if "emailAddress" not in want_fl:
            want_fl.insert(2, "emailAddress")
        for f, after in FORM_ADD:
            if f not in want_fl:
                want_fl.insert(want_fl.index(after) + 1 if after in want_fl else len(want_fl), f)
        want_fp = merge(copy.deepcopy(fp), {"emailAddress": {"required": True}, "birthDate": {"required": True}})
        if (want_fl, want_fp) != (fl, fp):
            say("form %r: add %s; email required" % (full["name"], [f for f in want_fl if f not in fl] or "none"))
            if apply:
                api("PUT", "LeadCapture/" + lc["id"], hdr, {"fieldList": want_fl, "fieldParams": want_fp})
        else:
            print("form %r is up to date" % full["name"])

    # ---- outgoing email (sign-in links go out at once, not on the robot's next run).
    # EspoCRM 10 sends "system" email through the group email account whose address is
    # Settings -> outboundEmailFromAddress (the old smtpServer settings are not used).
    # Send-only: IMAP stays off, so nothing is fetched from the mailbox.
    if not os.path.exists(SMTP_FILE):
        sys.exit("missing %s (two lines: office@ address, Proton SMTP token)" % SMTP_FILE)
    smtp_user, smtp_token = (open(SMTP_FILE).read().split() + ["", ""])[:2]
    acct = {"name": "Office (sending only)", "emailAddress": smtp_user, "status": "Active",
            "useImap": False, "useSmtp": True, "smtpHost": SMTP_HOST, "smtpPort": SMTP_PORT,
            "smtpAuth": True, "smtpSecurity": "TLS", "smtpUsername": smtp_user,
            "fromName": "Pack Leader K9", "storeSentEmails": False,
            # Shared: office users (admin) can also pick it as "From" when writing an email
            # in the office book; without it compose says "SMTP is not configured" (2026-09-28).
            "smtpIsShared": True}
    have = api("GET", "InboundEmail", hdr, params={"where[0][type]": "equals", "where[0][attribute]": "emailAddress",
                                                   "where[0][value]": smtp_user})["list"]
    if not have:
        say("outgoing email: create send-only account %s via %s:%d" % (smtp_user, SMTP_HOST, SMTP_PORT))
        if apply:
            api("POST", "InboundEmail", hdr, dict(acct, smtpPassword=smtp_token))
    else:
        cur_acct = api("GET", "InboundEmail/" + have[0]["id"], hdr)
        diff = {k: v for k, v in acct.items() if cur_acct.get(k) != v}
        if diff:
            say("outgoing email: account %s -> %s (password left as is)" % (smtp_user, ", ".join(sorted(diff))))
            if apply:
                api("PUT", "InboundEmail/" + have[0]["id"], hdr, diff)
        else:
            print("outgoing email account is set up")
    # Every client is in the US: the phone picker offers only the US.
    if api("GET", "Settings", hdr).get("phoneNumberPreferredCountryList") != ["us"]:
        say("phone numbers: preferred country list -> US only")
        if apply:
            api("PUT", "Settings", hdr, {"phoneNumberPreferredCountryList": ["us"]})
    tabs = api("GET", "Settings", hdr).get("tabList") or []
    if not any(isinstance(t, dict) and t.get("id") == BOARD_TAB["id"] for t in tabs):
        at = tabs.index("Stay") + 1 if "Stay" in tabs else len(tabs)
        say("top bar: add 'Kennel board' tab after Bookings")
        if apply:
            api("PUT", "Settings", hdr, {"tabList": tabs[:at] + [BOARD_TAB] + tabs[at:]})
    tabs = api("GET", "Settings", hdr).get("tabList") or []
    if "LessonPackage" not in tabs:
        at = tabs.index("Meeting") + 1 if "Meeting" in tabs else len(tabs)
        say("top bar: add 'Lesson packages' tab after Lessons")
        if apply:
            api("PUT", "Settings", hdr, {"tabList": tabs[:at] + ["LessonPackage"] + tabs[at:]})
    cur = api("GET", "Settings", hdr)
    want = {"outboundEmailFromAddress": smtp_user, "outboundEmailFromName": "Pack Leader K9",
            "outboundEmailIsShared": False}
    if any(cur.get(k) != v for k, v in want.items()):
        say("outgoing email: system address -> %s" % smtp_user)
        if apply:
            api("PUT", "Settings", hdr, want)

    # ---- role + API user
    roles = api("GET", "Role", hdr, params={"where[0][type]": "equals", "where[0][attribute]": "name",
                                            "where[0][value]": ROLE["name"]})["list"]
    users = api("GET", "User", hdr, params={"where[0][type]": "equals", "where[0][attribute]": "userName",
                                            "where[0][value]": "office-flow"})["list"]
    say("role %r: %s" % (ROLE["name"], "exists, update" if roles else "create"))
    say("API user kennel-dash with role %r (read only), key -> %s" % (DASH_ROLE["name"], DASH_KEY_OUT))
    say("API user office-flow: %s" % ("exists" if users else "create"))
    if apply:
        role_id = api("PUT", "Role/" + roles[0]["id"], hdr, ROLE)["id"] if roles else \
            api("POST", "Role", hdr, ROLE)["id"]
        if users:
            uid = users[0]["id"]
        else:
            uid = api("POST", "User", hdr, {"userName": "office-flow", "lastName": "Office robot",
                                           "type": "api", "authMethod": "ApiKey",
                                           "rolesIds": [role_id], "isActive": True})["id"]
        key = api("GET", "User/" + uid, hdr).get("apiKey")
        if not key:
            sys.exit("user office-flow has no apiKey. Admin -> API Users -> office-flow shows it.")
        fd = os.open(KEY_OUT, os.O_WRONLY | os.O_CREAT | os.O_TRUNC, 0o600)
        os.write(fd, (key + "\n").encode())
        os.close(fd)
        print("key: %s" % KEY_OUT)

        # ---- verify with the new user's own key
        r = urllib.request.Request(ESPO + "Lead?maxSize=1&select=status,paymentStatus,flowNote,dogSize",
                                   headers={"X-Api-Key": key})
        with urllib.request.urlopen(r, timeout=30) as x:
            print("verify: office-flow can read Requests with the new fields (%d total)" % json.load(x)["total"])
    else:
        print("\nNothing changed. Run again with --apply.")
        return

    # ---- read-only API user for the console's kennel panel
    droles = api("GET", "Role", hdr, params={"where[0][type]": "equals", "where[0][attribute]": "name",
                                             "where[0][value]": DASH_ROLE["name"]})["list"]
    dusers = api("GET", "User", hdr, params={"where[0][type]": "equals", "where[0][attribute]": "userName",
                                             "where[0][value]": "kennel-dash"})["list"]
    drole = api("PUT", "Role/" + droles[0]["id"], hdr, DASH_ROLE)["id"] if droles else \
        api("POST", "Role", hdr, DASH_ROLE)["id"]
    duid = dusers[0]["id"] if dusers else api("POST", "User", hdr, {
        "userName": "kennel-dash", "lastName": "Kennel dash", "type": "api", "authMethod": "ApiKey",
        "rolesIds": [drole], "isActive": True})["id"]
    dkey = api("GET", "User/" + duid, hdr).get("apiKey")
    fd = os.open(DASH_KEY_OUT, os.O_WRONLY | os.O_CREAT | os.O_TRUNC, 0o600)
    os.write(fd, (dkey + "\n").encode())
    os.close(fd)
    r = urllib.request.Request(ESPO + "Stay?maxSize=1", headers={"X-Api-Key": dkey})
    with urllib.request.urlopen(r, timeout=30) as x:
        print("kennel-dash (read only): key %s, can read Bookings (%d)" % (DASH_KEY_OUT, json.load(x)["total"]))
    r = urllib.request.Request(ESPO + "Stay", method="POST", data=b"{}",
                               headers={"X-Api-Key": dkey, "Content-Type": "application/json"})
    try:
        urllib.request.urlopen(r, timeout=30)
        sys.exit("kennel-dash could CREATE a booking - role is wrong. Stopping.")
    except urllib.error.HTTPError as e:
        print("kennel-dash cannot write (create refused: %d)" % e.code)


if __name__ == "__main__":
    main()
