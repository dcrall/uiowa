/**
 * @file
 * Fetch University of Iowa alerts.
 */

(($, Drupal, drupalSettings, once) => {
  // Attach uiowaAlertsGetAlerts behavior.
  Drupal.behaviors.uiowaAlerts = {
    attach: (context, settings) => {
      $(once('uiowaAlertsGetAlerts', '.block-uiowa-alerts-block', context)).each(() => {
        const messages = new Drupal.Message($('.hawk-alerts-wrapper')[0]);

        const updateAlerts = () => {
          // Get the alerts feed and track IDs as "new" alerts.
          $.ajax({
            url: settings.uiowaAlerts.source + '?include=id',
            dataType: "json",
            success: (response) => {
              let new_alerts = [];

              $.each(response.data, (i, item) => {
                let id = `hawk-alert-${item.attributes.date}`;
                new_alerts.push(id);

                if (!messages.select(id)) {
                  let date = new Date(item.attributes.date); // parse the ISO 8601 timestamp

                  // Create DateTimeFormat instances with the options
                  let monthFormatter = new Intl.DateTimeFormat('en-US', { month: 'long', timeZone: 'America/Chicago' });
                  let timeFormatter = new Intl.DateTimeFormat('en-US', { hour: 'numeric', minute: '2-digit', timeZone: 'America/Chicago', hour12: true });

                  // Format the date and time
                  let month = monthFormatter.format(date);
                  let time = timeFormatter.format(date).replace(' AM', 'am').replace(' PM', 'pm');

                  let alert = `
<div class="alert alert--icon alert--danger">
  <div class="alert__icon">
    <span class="fa-stack fa-1x">
      <span role="presentation" class="fas fa-circle fa-stack-2x"></span>
      <span role="presentation" class="fas fa-stack-1x fa-inverse fa-exclamation"></span>
    </span>
  </div>
  <div class="hawk-alert-message" role="region" aria-label="hawk alert message">
    <h2 class="headline headline--serif">
      <span class="hawk-alert-heading">
        <span class="hawk-alert-label">Hawk Alert</span>
      </span>
    </h2>
    <p><em><span class="hawk-alert-date">${month} ${date.getDate()}, ${date.getFullYear()} - ${time}</span></em><br />
      <span class="hawk-alert-body">${item.attributes.alert}</span>
      <a class="hawk-alert-link alert-link" href=${item.attributes.more_info_link}>Visit ${item.attributes.more_info_link} for more information.</a></p>
  </div>
</div>
        `;
                  messages.add(alert, {
                    id: id,
                    type: 'warning'
                  });
                }
              });

              let existing_alerts = [];

              // Get the existing alerts on the page and track IDs.
              document.querySelectorAll('.hawk-alerts-wrapper .messages').forEach( (existing_alert) => {
                existing_alerts.push(existing_alert.getAttribute('data-drupal-message-id'));
              });

              // Return any existing alerts that are not in the feed anymore.
              let difference = existing_alerts.filter(x => !new_alerts.includes(x));

              // Remove any closed alerts.
              difference.forEach((closed) => {
                messages.remove(closed);
              })
            }
          });
        };

        // Get alerts on page load.
        updateAlerts(messages);

        // Check for changes every 30 seconds.
        setInterval(updateAlerts, 30000, messages);
      });
    }
  };
})(jQuery, Drupal, drupalSettings, once);
