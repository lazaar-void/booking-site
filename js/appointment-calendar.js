(function (Drupal, drupalSettings) {
  'use strict';

  Drupal.behaviors.appointmentCalendar = {
    attach: function (context, settings) {
      const calendarEl = context.querySelector('#appointment-calendar');
      if (!calendarEl || calendarEl.dataset.initialized) {
        return;
      }

      // Mark as initialized to prevent double-initialization on AJAX rebuilds.
      calendarEl.dataset.initialized = 'true';

      const config = settings.appointment.calendar;
      const dateField = context.querySelector('#selected-date');
      const timeField = context.querySelector('#selected-time');
      const nextBtn = context.querySelector('.btn-next');

      const calendar = new FullCalendar.Calendar(calendarEl, {
        initialView: 'timeGridWeek',
        headerToolbar: {
          left: 'prev,next today',
          center: 'title',
          right: 'dayGridMonth,timeGridWeek,timeGridDay'
        },
        slotDuration: config.slot_duration,
        initialDate: config.initial_date,
        // FullCalendar adds 'start' and 'end' query params automatically.
        events: '/api/appointment/slots-range/' + config.adviser_id,
        allDaySlot: false,
        slotMinTime: '08:00:00',
        slotMaxTime: '19:00:00',
        height: 'auto',
        slotLabelFormat: {
          hour: '2-digit',
          minute: '2-digit',
          hour12: false
        },
        eventDisplay: 'block',
        eventClick: function (info) {
          const props = info.event.extendedProps;
          if (props.date && props.time) {
            // Update hidden fields.
            dateField.value = props.date;
            timeField.value = props.time;

            // Clear previous selection highlight from ALL events.
            calendar.getEvents().forEach(e => {
              if (e.extendedProps.isSelected) {
                e.setProp('backgroundColor', '#28a745');
                e.setProp('borderColor', '#1e7e34');
                e.setExtendedProp('isSelected', false);
              }
            });

            // Highlight current selection.
            info.event.setProp('backgroundColor', '#007bff');
            info.event.setProp('borderColor', '#0056b3');
            info.event.setExtendedProp('isSelected', true);

            // Give visual feedback in the next button.
            if (nextBtn) {
              nextBtn.classList.remove('is-disabled');
            }
          }
        }
      });

      calendar.render();
    }
  };

})(Drupal, drupalSettings);
