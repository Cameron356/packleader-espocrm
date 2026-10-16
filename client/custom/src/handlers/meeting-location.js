/**
 * Lesson edit form: choosing "Client's home" as the location fills the address
 * from the client's Contact (the robot saves it there the first time a home
 * lesson is booked), if the address box is still empty.
 */
define('custom:handlers/meeting-location', ['dynamic-handler'], (Dep) => {
    return class extends Dep {
        init() {
            this.recordView.listenTo(this.model, 'change:lessonLocation', (model, value) => {
                const ids = this.model.get('contactsIds') || [];

                if (value !== 'ClientHome' || this.model.get('lessonAddress') || !ids.length) {
                    return;
                }

                Espo.Ajax.getRequest('Contact/' + ids[0]).then(c => {
                    const addr = [c.addressStreet, c.addressCity].filter(x => x).join(', ');

                    if (addr && this.model.get('lessonLocation') === 'ClientHome' && !this.model.get('lessonAddress')) {
                        this.model.set('lessonAddress', addr);
                    }
                });
            });
        }
    };
});
