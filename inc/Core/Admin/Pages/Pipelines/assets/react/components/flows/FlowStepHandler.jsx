/**
 * Flow step handler component.
 *
 * Supports displaying multiple handler badges when a step has more than one
 * handler assigned (multi-handler mode).
 */

/**
 * WordPress dependencies
 */
import { Button } from '@wordpress/components';
import { __ } from '@wordpress/i18n';
/**
 * Internal dependencies
 */
import { useHandlers } from '../../queries/handlers';
import { useStepTypes } from '../../queries/config';

export default function FlowStepHandler( {
	handlerSlug,
	handlerSlugs,
	settingsDisplay,
	onConfigure,
	onAddHandler,
	showConfigureButton = true,
	showBadge = true,
} ) {
	const { data: handlers = {} } = useHandlers();
	const { data: stepTypes = {} } = useStepTypes();

	// Resolve to array — prefer handlerSlugs, fall back to single handlerSlug.
	const slugs = handlerSlugs || ( handlerSlug ? [ handlerSlug ] : [] );

	if ( slugs.length === 0 ) {
		return (
			<div className="datamachine-flow-step-handler datamachine-flow-step-handler--empty datamachine-handler-warning">
				<p className="datamachine-handler-warning-text">
					{ __( 'No handler configured', 'data-machine' ) }
				</p>
				<Button
					variant="secondary"
					size="small"
					onClick={ () => onConfigure && onConfigure( null ) }
				>
					{ __( 'Configure Handler', 'data-machine' ) }
				</Button>
			</div>
		);
	}

	const displaySettings = Array.isArray( settingsDisplay )
		? settingsDisplay.reduce( ( acc, setting ) => {
				acc[ setting.key ] = {
					label: setting.label,
					value: setting.display_value ?? setting.value,
				};
				return acc;
		  }, {} )
		: {};

	const hasSettings = Object.keys( displaySettings ).length > 0;

	/**
	 * Resolve a handler label from handlers registry, step types, or slug.
	 *
	 * @param {string} slug Handler slug.
	 * @return {string} Display label.
	 */
	const getLabel = ( slug ) =>
		handlers[ slug ]?.label || stepTypes[ slug ]?.label || slug;

	return (
		<div className="datamachine-flow-step-handler datamachine-handler-container">
			{ showBadge && (
				<div className="datamachine-handler-badges">
					{ slugs.map( ( slug ) => (
						<div
							key={ slug }
							className="datamachine-handler-tag datamachine-handler-badge"
							onClick={ () => onConfigure && onConfigure( slug ) }
							role="button"
							tabIndex={ 0 }
							onKeyDown={ ( e ) => {
								if ( e.key === 'Enter' || e.key === ' ' ) {
									onConfigure && onConfigure( slug );
								}
							} }
						>
							{ getLabel( slug ) }
						</div>
					) ) }
					{ onAddHandler && (
						<button
							type="button"
							className="datamachine-handler-add-badge"
							onClick={ onAddHandler }
							title={ __( 'Add another handler', 'data-machine' ) }
						>
							+
						</button>
					) }
				</div>
			) }

			{ hasSettings && (
				<div className="datamachine-handler-settings-display">
					{ Object.entries( displaySettings ).map(
						( [ key, setting ] ) => (
							<div
								key={ key }
								className="datamachine-handler-settings-entry"
							>
								<strong>{ setting.label }:</strong>{ ' ' }
								{ setting.value }
							</div>
						)
					) }
				</div>
			) }

			{ showConfigureButton && (
				<Button
					variant="secondary"
					size="small"
					onClick={ () => onConfigure && onConfigure( slugs[ 0 ] ) }
				>
					{ __( 'Configure', 'data-machine' ) }
				</Button>
			) }
		</div>
	);
}
