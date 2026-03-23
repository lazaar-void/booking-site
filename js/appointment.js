/**
 * @file
 * Appointment booking wizard JS — dynamic slot loading when date changes.
 */
(function ($, Drupal, drupalSettings) {
  'use strict';

  Drupal.behaviors.appointmentWizard = {
    attach: function (context, settings) {
      // When the date input changes, fetch fresh slots via the JSON endpoint.
      const $datePicker = $('#appointment-date-picker', context);
      if (!$datePicker.length) return;

      $datePicker.once('appointment-date-change').on('change', function () {
        const date      = $(this).val();
        const adviserId = $('input[name="adviser_id"]:checked').val()
                       || drupalSettings.appointment?.adviserId;

        if (!date || !adviserId) return;

        const url = '/api/appointment/slots/' + adviserId + '/' + date;
        $.getJSON(url, function (data) {
          const $wrapper = $('#time-slots-wrapper');
          const slots    = data.slots || [];

          // Rebuild the radio list.
          $wrapper.find('input[type="radio"]').each(function () {
            $(this).remove();
          });
          $wrapper.find('.form-item').remove();

          if (slots.length === 0) {
            $wrapper.html('<p>' + Drupal.t('No slots available for this date.') + '</p>');
            return;
          }

          const $radios = $('<div class="form-radios"></div>');
          slots.forEach(function (slot) {
            $radios.append(
              '<div class="form-item">'
              + '<input type="radio" name="time" value="' + slot + '" id="slot-' + slot + '">'
              + '<label for="slot-' + slot + '">' + slot + '</label>'
              + '</div>'
            );
          });
          $wrapper.html($radios);
        });
      });
    }
  };

}(jQuery, Drupal, drupalSettings));
