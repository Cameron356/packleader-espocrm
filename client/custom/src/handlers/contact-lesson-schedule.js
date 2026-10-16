/**
 * Contact menu -> "Lesson schedule": opens the client's lessons (times and
 * locations) in a new tab, ready to print, copy or download
 * (EntryPoints/LessonSchedule.php).
 */
define('custom:handlers/contact-lesson-schedule', [], () => {
    return class {
        constructor(view) {
            this.view = view;
        }

        open() {
            window.open('?entryPoint=lessonSchedule&id=' + encodeURIComponent(this.view.model.id), '_blank');
        }
    };
});
