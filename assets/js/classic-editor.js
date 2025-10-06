(function($) {
    'use strict';

    if (!window.XEFIEditorData) {
        return;
    }

    const data = window.XEFIEditorData;
    const supportsFlickr = !!(data.settings && data.settings.supportsFlickr);

    const isHttps = (value) => /^https:\/\//i.test(value);
    const imageExtensions = (data.validation && data.validation.imageExtensions) || ['jpg', 'jpeg', 'png'];
    const imageRegex = new RegExp(`\\.(${imageExtensions.join('|')})($|[?&#])`, 'i');
    const isDirectImage = (value) => imageRegex.test(value);
    const isFlickrUrl = (value) => /^https:\/\/(?:www\.)?flickr\.com\/photos\/[^/]+\/\d+(?:\/|$)/i.test(value);

    let previewContainer = null;
    let currentRequest = null;

    function createPreviewContainer() {
        if (!previewContainer) {
            previewContainer = $('<div class="xefi-preview" style="margin-top: 12px;"></div>');
            $('#xefi-external-url').after(previewContainer);
        }
        return previewContainer;
    }

    function showPreview(imageUrl) {
        const container = createPreviewContainer();
        container.html(
            '<img src="' + imageUrl + '" alt="Preview" style="width: 100%; height: auto; border-radius: 4px; border: 1px solid #ddd;" />'
        );
    }

    function showLoading() {
        const container = createPreviewContainer();
        container.html('<span class="spinner is-active" style="float: none; margin: 0;"></span> <span>Resolving preview…</span>');
    }

    function hidePreview() {
        if (previewContainer) {
            previewContainer.empty();
        }
    }

    function updatePreview() {
        const source = $('input[name="_xefi_source"]:checked').val();
        const url = $('#xefi-external-url').val();

        if (currentRequest) {
            currentRequest.abort();
            currentRequest = null;
        }

        if (source !== 'external' || !url) {
            hidePreview();
            return;
        }

        if (!isHttps(url)) {
            hidePreview();
            return;
        }

        // Direct image - show immediately
        if (isDirectImage(url)) {
            showPreview(url);
            return;
        }

        // Flickr URL - resolve via API
        if (isFlickrUrl(url)) {
            if (!supportsFlickr) {
                hidePreview();
                return;
            }

            showLoading();

            const postId = $('#post_ID').val() || 0;

            currentRequest = $.ajax({
                url: window.wpApiSettings.root + 'xefi/v1/resolve',
                method: 'POST',
                beforeSend: function(xhr) {
                    xhr.setRequestHeader('X-WP-Nonce', window.wpApiSettings.nonce);
                },
                data: JSON.stringify({
                    url: url,
                    postId: parseInt(postId, 10)
                }),
                contentType: 'application/json',
                success: function(response) {
                    currentRequest = null;
                    if (response && response.url) {
                        showPreview(response.url);
                    } else {
                        hidePreview();
                    }
                },
                error: function() {
                    currentRequest = null;
                    hidePreview();
                }
            });

            return;
        }

        hidePreview();
    }

    $(document).ready(function() {
        // Update preview on source change
        $('input[name="_xefi_source"]').on('change', updatePreview);

        // Update preview on URL change (with debounce)
        let urlTimeout;
        $('#xefi-external-url').on('input', function() {
            clearTimeout(urlTimeout);
            urlTimeout = setTimeout(updatePreview, 400);
        });

        // Initial preview
        updatePreview();
    });

})(jQuery);
