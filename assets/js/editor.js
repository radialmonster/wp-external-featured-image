( function ( wp ) {
    if ( ! wp || ! wp.plugins || ! wp.editPost ) {
        return;
    }

    const { registerPlugin } = wp.plugins;
    const { PluginDocumentSettingPanel } = wp.editPost;
    const { RadioControl, TextControl, Notice } = wp.components;
    const { useSelect, useDispatch } = wp.data;
    const { __ } = wp.i18n;
    const { Fragment, useMemo } = wp.element;

    const data = window.XEFIEditorData || {};
    const strings = data.strings || {};
    const validation = data.validation || {};
    const supportsFlickr = !! ( data.settings && data.settings.supportsFlickr );

    const SOURCE_MEDIA = 'media';
    const SOURCE_EXTERNAL = 'external';

    const ensureString = ( value ) => ( 'string' === typeof value ? value : '' );

    const isHttps = ( value ) => /^https:\/\//i.test( value );
    const imageExtensions = validation.imageExtensions || [ 'jpg', 'jpeg', 'png' ];
    const imageRegex = new RegExp( `\.(${ imageExtensions.join( '|' ) })($|[?&#])`, 'i' );
    const isDirectImage = ( value ) => imageRegex.test( value );
    const isFlickrUrl = ( value ) => /^https:\/\/(?:www\.)?flickr\.com\/photos\/[^/]+\/\d+(?:\/|$)/i.test( value );

    const Panel = () => {
        const { meta, source, url, resolvedUrl, error, hasFeatured } = useSelect( ( select ) => {
            const editor = select( 'core/editor' );
            const currentMeta = editor.getEditedPostAttribute( 'meta' ) || {};
            return {
                meta: currentMeta,
                source: ensureString( currentMeta._xefi_source ) || SOURCE_MEDIA,
                url: ensureString( currentMeta._xefi_url ),
                resolvedUrl: ensureString( currentMeta._xefi_resolved_url ),
                error: ensureString( currentMeta._xefi_error ),
                hasFeatured: !! editor.getEditedPostAttribute( 'featured_media' ),
            };
        }, [] );

        const { editPost } = useDispatch( 'core/editor' );

        const updateMeta = ( updates ) => {
            editPost( { meta: { ...meta, ...updates } } );
        };

        const validationMessage = useMemo( () => {
            if ( source !== SOURCE_EXTERNAL ) {
                return '';
            }

            if ( ! url ) {
                return '';
            }

            if ( ! isHttps( url ) ) {
                return strings.invalidUrl || __( 'Enter a valid HTTPS image URL or Flickr page URL.', 'wp-external-featured-image' );
            }

            if ( isFlickrUrl( url ) ) {
                if ( ! supportsFlickr ) {
                    return strings.flickrApiKeyRequired || __( 'Add a Flickr API key to resolve Flickr URLs.', 'wp-external-featured-image' );
                }

                return '';
            }

            if ( ! isDirectImage( url ) ) {
                return strings.invalidUrl || __( 'Enter a valid HTTPS image URL or Flickr page URL.', 'wp-external-featured-image' );
            }

            return '';
        }, [ source, url, supportsFlickr ] );

        const combinedError = validationMessage || error;

        const previewUrl = useMemo( () => {
            if ( source !== SOURCE_EXTERNAL ) {
                return '';
            }

            if ( combinedError ) {
                return '';
            }

            // For direct images, show immediately
            if ( url && isDirectImage( url ) && isHttps( url ) ) {
                return url;
            }

            // For Flickr URLs, show resolved URL if available
            if ( url && isFlickrUrl( url ) && resolvedUrl ) {
                return resolvedUrl;
            }

            return '';
        }, [ source, url, resolvedUrl, combinedError ] );

        return (
            <PluginDocumentSettingPanel
                name="xefi-featured-image-source"
                title={ strings.panelTitle || __( 'Featured Image Source', 'wp-external-featured-image' ) }
                className="xefi-featured-image-panel"
            >
                <RadioControl
                    selected={ source }
                    options={ [
                        { label: strings.mediaLibrary || __( 'Media Library', 'wp-external-featured-image' ), value: SOURCE_MEDIA },
                        { label: strings.externalSource || __( 'External', 'wp-external-featured-image' ), value: SOURCE_EXTERNAL },
                    ] }
                    onChange={ ( value ) => updateMeta( { _xefi_source: value } ) }
                />
                { source === SOURCE_EXTERNAL && (
                    <Fragment>
                        <TextControl
                            type="url"
                            label={ strings.fieldLabel || __( 'External image or Flickr page URL', 'wp-external-featured-image' ) }
                            help={ strings.helperText || __( 'Paste a direct image URL (.jpg/.png) or a Flickr photo URL.', 'wp-external-featured-image' ) }
                            value={ url }
                            onChange={ ( value ) => {
                                updateMeta( { _xefi_url: value } );
                            } }
                        />
                        { combinedError && (
                            <Notice status="error" isDismissible={ false }>{ combinedError }</Notice>
                        ) }
                        { previewUrl && (
                            <div style={ { marginTop: '12px' } }>
                                <img
                                    src={ previewUrl }
                                    alt={ __( 'External featured image preview', 'wp-external-featured-image' ) }
                                    style={ {
                                        width: '100%',
                                        height: 'auto',
                                        borderRadius: '4px',
                                        border: '1px solid #ddd'
                                    } }
                                />
                            </div>
                        ) }
                    </Fragment>
                ) }
                { hasFeatured && (
                    <Notice status="info" isDismissible={ false }>
                        { strings.nativeOverride || __( 'A native featured image is set. It will override the external image.', 'wp-external-featured-image' ) }
                    </Notice>
                ) }
            </PluginDocumentSettingPanel>
        );
    };

    registerPlugin( 'xefi-featured-image-source', { render: Panel } );
} )( window.wp );
