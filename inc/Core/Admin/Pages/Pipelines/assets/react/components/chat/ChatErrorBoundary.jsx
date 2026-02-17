/**
 * ChatErrorBoundary Component
 *
 * React error boundary for the chat sidebar.
 * Catches rendering errors and displays a fallback UI with retry option.
 */

/**
 * WordPress dependencies
 */
import { Component } from '@wordpress/element';
import { Button } from '@wordpress/components';
import { __ } from '@wordpress/i18n';

export default class ChatErrorBoundary extends Component {
	constructor( props ) {
		super( props );
		this.state = { hasError: false };
	}

	static getDerivedStateFromError() {
		return { hasError: true };
	}

	componentDidCatch( error, errorInfo ) {
		// eslint-disable-next-line no-console
		console.error( '[ChatErrorBoundary]', error, errorInfo );
	}

	render() {
		if ( this.state.hasError ) {
			return (
				<div className="datamachine-chat-sidebar__error">
					<p>
						{ __(
							'Something went wrong with the chat.',
							'data-machine'
						) }
					</p>
					<Button
						variant="secondary"
						onClick={ () => this.setState( { hasError: false } ) }
					>
						{ __( 'Try again', 'data-machine' ) }
					</Button>
				</div>
			);
		}

		return this.props.children;
	}
}
