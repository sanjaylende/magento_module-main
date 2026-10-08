/**
 * Row action for the Video Generator grid: opens the adapter's page for the clicked product in a modal. When the
 * modal closes the grid reloads so status badges reflect what was generated.
 */
define([
    'jquery',
    'uiRegistry',
    'Magento_Ui/js/grid/columns/actions',
    'Magento_Ui/js/modal/modal'
], function ($, registry, Actions) {
    'use strict';

    return Actions.extend({
        defaults: {
            gridProvider: 'flipick_video_listing.flipick_video_listing_data_source'
        },

        /**
         * @param {String} actionIndex
         * @param {String} recordId - the product's uniqueTag
         */
        defaultCallback: function (actionIndex, recordId) {
            var provider = registry.get(this.gridProvider),
                row = (this.rows || []).filter(function (r) {
                    return r.uniqueTag === recordId;
                })[0] || {};

            if (!row.launch_url) {
                return;
            }
            // A fresh one-time launch URL per click: the adapter accepts each launch token once.
            $.getJSON(row.launch_url, { website: row.website_id, product: recordId }).done(function (result) {
                var $modal = $('<div class="flipick-video-modal-content"></div>');

                $('<iframe/>', { src: result.url, title: 'Flipick Video Generator' }).appendTo($modal);
                $modal.modal({
                    type: 'slide',
                    title: row.name || 'Generate Video with Flipick',
                    modalClass: 'flipick-video-modal',
                    buttons: [],
                    closed: function () {
                        $modal.remove();
                        if (provider) {
                            provider.reload({ refresh: true });
                        }
                    }
                }).modal('openModal');
            }).fail(function (xhr) {
                var message = xhr.responseJSON && xhr.responseJSON.error ? xhr.responseJSON.error : 'Could not open Video Generator.';

                window.alert(message);
            });
        }
    });
});
