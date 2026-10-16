/**
 * Booking edit form: picking a dog fills its care details (food, feeding,
 * medications, special care) from the dog's record, and the emergency contact
 * from the dog's owner (Contact), into any of those fields still empty, so
 * they can be checked and changed before saving. Anything already typed is
 * left alone. The server does the same on save
 * (Hooks/Stay/DogCare.php).
 */
define('custom:handlers/stay-dog-care', ['dynamic-handler'], (Dep) => {
    const FIELDS = ['foodSource', 'feeding', 'medications', 'specialCare'];
    const OWNER_FIELDS = ['emergencyName', 'emergencyPhone'];
    const empty = v => v === null || v === undefined || v === '';

    return class extends Dep {
        init() {
            this.recordView.listenTo(this.model, 'change:dogId', (model, value) => {
                if (!value) {
                    return;
                }

                Espo.Ajax.getRequest('Dog/' + value)
                    .then(dog => {
                        this.fillEmpty(value, dog, FIELDS);

                        return dog.contactId ? Espo.Ajax.getRequest('Contact/' + dog.contactId) : null;
                    })
                    .then(owner => {
                        if (owner) {
                            this.fillEmpty(value, owner, OWNER_FIELDS);
                        }
                    });
            });
        }

        fillEmpty(dogId, from, fields) {
            if (this.model.get('dogId') !== dogId) {
                return;
            }

            const fill = {};

            fields.forEach(f => {
                if (empty(this.model.get(f)) && !empty(from[f])) {
                    fill[f] = from[f];
                }
            });

            if (Object.keys(fill).length) {
                this.model.set(fill);
            }
        }
    };
});
