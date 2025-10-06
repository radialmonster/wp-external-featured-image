( function ( wp ) {
    if ( ! wp || ! wp.plugins || ! wp.editPost ) {
        return;
    }

    const { registerPlugin } = wp.plugins;
    const PluginDocumentSettingPanel = ( wp.editor && wp.editor.PluginDocumentSettingPanel ) || wp.editPost.PluginDocumentSettingPanel;
    const { RadioControl, TextControl, Notice, Spinner } = wp.components;
    const { useSelect, useDispatch } = wp.data;
    const { __ } = wp.i18n;
    const { Fragment, createElement, useMemo, useEffect, useState, useRef } = wp.element;
    const apiFetch = wp.apiFetch;

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
        const { meta, source, url, resolvedUrl, error, photoId, hasFeatured, postId } = useSelect( ( select ) => {
            const editor = select( 'core/editor' );
            const currentMeta = editor.getEditedPostAttribute( 'meta' ) || {};
            return {
                meta: currentMeta,
                source: ensureString( currentMeta._xefi_source ) || SOURCE_MEDIA,
                url: ensureString( currentMeta._xefi_url ),
                resolvedUrl: ensureString( currentMeta._xefi_resolved ),
                error: ensureString( currentMeta._xefi_error ),
                photoId: ensureString( currentMeta._xefi_photo_id ),
                hasFeatured: !! editor.getEditedPostAttribute( 'featured_media' ),
                postId: editor.getCurrentPostId ? editor.getCurrentPostId() : 0,
            };
        }, [] );

        const { editPost } = useDispatch( 'core/editor' );

        const updateMeta = ( updates ) => {
            editPost( { meta: { ...meta, ...updates } } );
        };

        const [ previewUrl, setPreviewUrl ] = useState( '' );
        const [ isResolving, setIsResolving ] = useState( false );
        const [ remoteError, setRemoteError ] = useState( '' );
        const requestRef = useRef( null );

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

        useEffect( () => {
            console.log( 'XEFI useEffect triggered', { source, url, resolvedUrl, validationMessage, supportsFlickr, previewUrl } );
            let controller = null;
            let timeoutId = null;
            let active = true;

            if ( requestRef.current ) {
                requestRef.current.abort();
                requestRef.current = null;
            }

            setRemoteError( '' );

            if ( source !== SOURCE_EXTERNAL ) {
                console.log( 'XEFI: source is not external' );
                setPreviewUrl( '' );
                setIsResolving( false );
                return () => {
                    active = false;
                };
            }

            if ( validationMessage ) {
                console.log( 'XEFI: validation message present', validationMessage );
                setPreviewUrl( '' );
                setIsResolving( false );
                return () => {
                    active = false;
                };
            }

            if ( url && isDirectImage( url ) && isHttps( url ) ) {
                console.log( 'XEFI: direct image detected', url );
                if ( previewUrl !== url ) {
                    setPreviewUrl( url );
                }
                if ( resolvedUrl !== url || error || photoId ) {
                    updateMeta( {
                        _xefi_resolved: url,
                        _xefi_error: '',
                        _xefi_photo_id: '',
                    } );
                }
                setIsResolving( false );
                return () => {
                    active = false;
                };
            }

            if ( url && isFlickrUrl( url ) ) {
                console.log( 'XEFI: Flickr URL detected', { url, resolvedUrl, supportsFlickr, apiFetch: !! apiFetch, previewUrl } );
                if ( resolvedUrl ) {
                    console.log( 'XEFI: using cached resolved URL', resolvedUrl );
                    if ( previewUrl !== resolvedUrl ) {
                        setPreviewUrl( resolvedUrl );
                    }
                    setIsResolving( false );
                    return () => {
                        active = false;
                    };
                }

                if ( ! supportsFlickr || ! apiFetch ) {
                    console.log( 'XEFI: Flickr not supported or apiFetch missing' );
                    setPreviewUrl( '' );
                    setIsResolving( false );
                    return () => {
                        active = false;
                    };
                }

                if ( previewUrl ) {
                    console.log( 'XEFI: previewUrl already set, skipping fetch' );
                    setIsResolving( false );
                    return () => {
                        active = false;
                    };
                }

                console.log( 'XEFI: fetching from API' );
                controller = new AbortController();
                requestRef.current = controller;
                setIsResolving( true );

                timeoutId = setTimeout( () => {
                    apiFetch( {
                        path: '/xefi/v1/resolve',
                        method: 'POST',
                        data: {
                            url,
                            postId: postId || 0,
                        },
                        signal: controller.signal,
                    } )
                        .then( ( response ) => {
                            if ( ! active || controller.signal.aborted ) {
                                return;
                            }

                            requestRef.current = null;
                            const resolvedResponseUrl = ensureString( response && response.url );
                            const resolvedPhotoId = ensureString( response && response.photo_id );
                            if ( resolvedResponseUrl ) {
                                setPreviewUrl( resolvedResponseUrl );
                                updateMeta( {
                                    _xefi_resolved: resolvedResponseUrl,
                                    _xefi_error: '',
                                    _xefi_photo_id: resolvedPhotoId,
                                } );
                            } else {
                                setPreviewUrl( '' );
                            }
                            setRemoteError( '' );
                            setIsResolving( false );
                        } )
                        .catch( ( fetchError ) => {
                            if ( ! active || controller.signal.aborted ) {
                                return;
                            }

                            requestRef.current = null;
                            const message = ensureString( fetchError && fetchError.message )
                                || ensureString( fetchError && fetchError.data && fetchError.data.message )
                                || strings.invalidUrl
                                || __( 'Enter a valid HTTPS image URL or Flickr page URL.', 'wp-external-featured-image' );

                            setRemoteError( message );
                            setPreviewUrl( '' );
                            updateMeta( {
                                _xefi_resolved: '',
                                _xefi_error: message,
                                _xefi_photo_id: '',
                            } );
                            setIsResolving( false );
                        } );
                }, 400 );

                return () => {
                    active = false;
                    if ( timeoutId ) {
                        clearTimeout( timeoutId );
                    }
                    if ( controller ) {
                        controller.abort();
                    }
                    requestRef.current = null;
                    setIsResolving( false );
                };
            }

            setPreviewUrl( '' );
            setIsResolving( false );

            return () => {
                active = false;
            };
        }, [ source, url, resolvedUrl, validationMessage, supportsFlickr, postId, error, photoId, previewUrl ] );

        const combinedError = validationMessage || remoteError || error;

        console.log( 'XEFI render', { previewUrl, isResolving, combinedError, source } );

        const children = [
            createElement( RadioControl, {
                selected: source,
                options: [
                    { label: strings.mediaLibrary || __( 'Media Library', 'wp-external-featured-image' ), value: SOURCE_MEDIA },
                    { label: strings.externalSource || __( 'External', 'wp-external-featured-image' ), value: SOURCE_EXTERNAL },
                ],
                onChange: ( value ) => updateMeta( { _xefi_source: value } ),
            } ),
        ];

        if ( source === SOURCE_EXTERNAL ) {
            children.push(
                createElement( TextControl, {
                    type: 'url',
                    label: strings.fieldLabel || __( 'External image or Flickr page URL', 'wp-external-featured-image' ),
                    help: strings.helperText || __( 'Paste a direct image URL (.jpg/.png) or a Flickr photo URL.', 'wp-external-featured-image' ),
                    value: url,
                    onChange: ( value ) => {
                        if ( value !== url ) {
                            updateMeta( {
                                _xefi_url: value,
                                _xefi_resolved: '',
                                _xefi_error: '',
                                _xefi_photo_id: '',
                            } );
                            setPreviewUrl( '' );
                            setRemoteError( '' );
                        } else {
                            updateMeta( { _xefi_url: value } );
                        }
                    },
                } )
            );

            if ( combinedError ) {
                children.push( createElement( Notice, { status: 'error', isDismissible: false }, combinedError ) );
            }

            if ( isResolving && ! previewUrl ) {
                children.push(
                    createElement(
                        'div',
                        {
                            style: {
                                marginTop: '12px',
                                display: 'flex',
                                alignItems: 'center',
                                gap: '8px',
                            },
                        },
                        createElement( Spinner, null ),
                        createElement( 'span', null, strings.resolvingPreview || __( 'Resolving preview…', 'wp-external-featured-image' ) )
                    )
                );
            }

            if ( previewUrl ) {
                console.log( 'XEFI: Adding image to children', previewUrl );
                children.push(
                    createElement(
                        'div',
                        { style: { marginTop: '12px' } },
                        createElement( 'img', {
                            src: previewUrl,
                            alt: __( 'External featured image preview', 'wp-external-featured-image' ),
                            style: {
                                width: '100%',
                                height: 'auto',
                                borderRadius: '4px',
                                border: '1px solid #ddd',
                            },
                        } )
                    )
                );
            }
        }

        if ( hasFeatured ) {
            children.push(
                createElement(
                    Notice,
                    { status: 'info', isDismissible: false },
                    strings.nativeOverride || __( 'A native featured image is set. It will override the external image.', 'wp-external-featured-image' )
                )
            );
        }

        return createElement.apply( null, [
            PluginDocumentSettingPanel,
            {
                name: 'xefi-featured-image-source',
                title: strings.panelTitle || __( 'Featured Image Source', 'wp-external-featured-image' ),
                className: 'xefi-featured-image-panel',
            }
        ].concat( children ) );
    };

    registerPlugin( 'xefi-featured-image-source', { render: Panel } );
} )( window.wp );
