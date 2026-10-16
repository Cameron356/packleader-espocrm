/**
 * "+" on a lesson package's Lessons panel: the new lesson comes pre-filled as a
 * private lesson for the package's dog and client, at the same location as the
 * package's latest lesson. The package link itself is set by the panel.
 */
define('custom:handlers/lesson-package-lesson', [], () => {
    return class {
        constructor(helper, options) {}

        getAttributes(model) {
            const dogId = model.get('dogId');
            const dogName = model.get('dogName');
            const contactId = model.get('contactId');
            const attributes = {
                service: 'PrivateLesson',
                status: 'Planned',
                name: 'Private lesson' + (dogName ? ' · ' + dogName : ''),
            };

            if (dogId) {
                Object.assign(attributes, {
                    dogId: dogId,
                    dogName: dogName,
                    dogsIds: [dogId],
                    dogsNames: {[dogId]: dogName},
                });
            }

            if (contactId) {
                Object.assign(attributes, {
                    contactsIds: [contactId],
                    contactsNames: {[contactId]: model.get('contactName')},
                });
            }

            return Espo.Ajax.getRequest('LessonPackage/' + model.id + '/lessons', {
                maxSize: 1, orderBy: 'dateStart', order: 'desc', select: 'lessonLocation,lessonAddress',
            })
                .then(r => {
                    const last = (r.list || [])[0];

                    if (last) {
                        attributes.lessonLocation = last.lessonLocation || '';
                        attributes.lessonAddress = last.lessonAddress || null;
                    }

                    return attributes;
                })
                .catch(() => attributes);
        }
    };
});
