/**
 * WordPress dependencies
 */
import { MediaUpload, MediaUploadCheck } from '@wordpress/block-editor';
import { Button, Flex } from '@wordpress/components';
import { store as coreStore, useEntityProp } from '@wordpress/core-data';
import { useSelect } from '@wordpress/data';
import { store as editorStore } from '@wordpress/editor';
import { __ } from '@wordpress/i18n';

/**
 * Preview URL for an attachment record.
 *
 * @param {Object|undefined} media Attachment from the core store.
 * @return {string} URL, or an empty string.
 */
function previewUrl( media ) {
	if ( ! media ) {
		return '';
	}

	return (
		media.media_details?.sizes?.medium?.source_url || media.source_url || ''
	);
}

/**
 * The "Social preview image" field: the image link previews use for this
 * post. Defaults to the featured image.
 *
 * @return {Element} The field.
 */
export default function SocialImage() {
	const { postType, featuredId } = useSelect(
		( select ) => ( {
			postType: select( editorStore ).getCurrentPostType(),
			featuredId:
				select( editorStore ).getEditedPostAttribute(
					'featured_media'
				) || 0,
		} ),
		[]
	);
	const [ meta, setMeta ] = useEntityProp( 'postType', postType, 'meta' );
	const imageId = Number( meta?._refract_social_image ) || 0;
	const shownId = imageId || featuredId;
	const media = useSelect(
		( select ) =>
			shownId ? select( coreStore ).getMedia( shownId ) : undefined,
		[ shownId ]
	);

	if ( ! meta || ! ( '_refract_social_image' in meta ) ) {
		return null;
	}

	const setImage = ( id ) =>
		setMeta( { ...meta, _refract_social_image: id } );
	const url = previewUrl( media );

	return (
		<div className="refract-social-image">
			<p className="refract-help">
				{ imageId
					? __( 'Link previews use this image.', 'refract' )
					: __(
							'Link previews use the featured image. Choose another image for social networks if you like.',
							'refract'
						) }
			</p>

			{ url && (
				<img
					className="refract-social-image__preview"
					src={ url }
					alt={ media?.alt_text || '' }
				/>
			) }

			<MediaUploadCheck>
				<Flex justify="flex-start" wrap>
					<MediaUpload
						title={ __( 'Social preview image', 'refract' ) }
						allowedTypes={ [ 'image' ] }
						value={ imageId || undefined }
						onSelect={ ( selected ) => setImage( selected.id ) }
						render={ ( { open } ) => (
							<Button variant="secondary" onClick={ open }>
								{ imageId
									? __( 'Replace image', 'refract' )
									: __( 'Choose image', 'refract' ) }
							</Button>
						) }
					/>
					{ imageId > 0 && (
						<Button
							variant="tertiary"
							onClick={ () => setImage( 0 ) }
						>
							{ __( 'Use the featured image', 'refract' ) }
						</Button>
					) }
				</Flex>
			</MediaUploadCheck>
		</div>
	);
}
