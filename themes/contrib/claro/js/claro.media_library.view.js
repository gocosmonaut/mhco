/**
* DO NOT EDIT THIS FILE.
* See the following change record for more information,
* https://www.drupal.org/node/2815083
* @preserve
**/

(function ($, Drupal) {
  Drupal.behaviors = Drupal.behaviors || {};

  Drupal.behaviors.MediaLibrarySelectAll = {
    attach: function attach(context) {
      var $view = $('.media-library-view', context).once('media-library-select-all');
      if ($view.length && $view.find('.media-library-item').length) {
        var $checkbox = $(Drupal.theme('checkbox')).on('click', function (_ref) {
          var currentTarget = _ref.currentTarget;

          var $checkboxes = $(currentTarget).closest('.media-library-view').find('.media-library-item input[type="checkbox"]');
          $checkboxes.prop('checked', $(currentTarget).prop('checked')).trigger('change');

          var announcement = $(currentTarget).prop('checked') ? Drupal.t('Zero items selected') : Drupal.t('All @count items selected', {
            '@count': $checkboxes.length
          });
          Drupal.announce(announcement);
        });
        var $label = $('<label class="media-library-select-all"></label>').text(Drupal.t('Select all media'));
        $label.prepend($checkbox);
        $view.find('.media-library-item').first().before($label);
      }
    }
  };
})(jQuery, Drupal);