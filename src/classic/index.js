/**
 * WordPress dependencies
 */
import domReady from '@wordpress/dom-ready';
import { createRoot } from '@wordpress/element';

/**
 * Internal dependencies
 */
import PostView from '../common/post-view';
import usePostPanel from '../common/use-post-panel';
import './index.scss';

/**
 * The versions panel inside the classic editor's Refract meta box. The
 * server renders the form fields; this shows the send state and versions.
 *
 * @param {Object} props
 * @param {number} props.postId Post ID.
 * @return {Element} The panel.
 */
function ClassicPanel( { postId } ) {
	const hook = usePostPanel( postId );
	const config = window.refractEditor || {};

	return (
		<PostView
			panel={ hook }
			settingsUrl={ config.settingsUrl }
			canManage={ Boolean( config.canManage ) }
		/>
	);
}

domReady( () => {
	const node = document.getElementById( 'refract-classic' );
	const postId = Number( node?.dataset.postId );

	if ( node && postId ) {
		createRoot( node ).render( <ClassicPanel postId={ postId } /> );
	}
} );
