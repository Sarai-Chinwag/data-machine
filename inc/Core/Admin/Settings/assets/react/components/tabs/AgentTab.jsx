/**
 * AgentTab Component
 *
 * AI agent settings including tools, system prompt, provider/model, and conversation limits.
 * Uses useFormState for form management and SettingsSaveBar for save UI.
 */

/**
 * WordPress dependencies
 */
import { useState, useEffect } from '@wordpress/element';
import { Button } from '@wordpress/components';

/**
 * Internal dependencies
 */
import { useSettings, useUpdateSettings } from '../../queries/settings';
import ToolConfigModal from '../ToolConfigModal';
import { useFormState } from '@shared/hooks/useFormState';
import SettingsSaveBar, {
	useSaveStatus,
} from '@shared/components/SettingsSaveBar';

/**
 * External dependencies
 */
import ProviderModelSelector from '@shared/components/ai/ProviderModelSelector';

const DEFAULTS = {
	enabled_tools: {},
	global_system_prompt: '',
	default_provider: '',
	default_model: '',
	site_context_enabled: false,
	max_turns: 12,
};

const AgentTab = () => {
	const { data, isLoading, error } = useSettings();
	const updateMutation = useUpdateSettings();
	const [ openToolId, setOpenToolId ] = useState( null );

	const form = useFormState( {
		initialData: DEFAULTS,
		onSubmit: ( formData ) => updateMutation.mutateAsync( formData ),
	} );

	const save = useSaveStatus( {
		onSave: () => form.submit(),
	} );

	// Sync server data → form state
	useEffect( () => {
		if ( data?.settings ) {
			form.reset( {
				enabled_tools: data.settings.enabled_tools || {},
				global_system_prompt:
					data.settings.global_system_prompt || '',
				default_provider: data.settings.default_provider || '',
				default_model: data.settings.default_model || '',
				site_context_enabled:
					data.settings.site_context_enabled ?? false,
				max_turns: data.settings.max_turns ?? 12,
			} );
			save.setHasChanges( false );
		}
	}, [ data ] ); // eslint-disable-line react-hooks/exhaustive-deps

	/**
	 * Update a field and mark the form as changed.
	 *
	 * @param {string} field Field key
	 * @param {*}      value New value
	 */
	const updateField = ( field, value ) => {
		form.updateField( field, value );
		save.markChanged();
	};

	const handleToolToggle = ( toolName, enabled ) => {
		const newTools = { ...form.data.enabled_tools };
		if ( enabled ) {
			newTools[ toolName ] = true;
		} else {
			delete newTools[ toolName ];
		}
		form.updateField( 'enabled_tools', newTools );
		save.markChanged();
	};

	const handleProviderChange = ( provider ) => {
		form.updateData( {
			default_provider: provider,
			default_model: '',
		} );
		save.markChanged();
	};

	if ( isLoading ) {
		return (
			<div className="datamachine-agent-tab-loading">
				<span className="spinner is-active"></span>
				<span>Loading agent settings...</span>
			</div>
		);
	}

	if ( error ) {
		return (
			<div className="notice notice-error">
				<p>Error loading settings: { error.message }</p>
			</div>
		);
	}

	const globalTools = data?.global_tools || {};

	return (
		<div className="datamachine-agent-tab">
			{ openToolId && (
				<ToolConfigModal
					toolId={ openToolId }
					isOpen={ Boolean( openToolId ) }
					onRequestClose={ () => setOpenToolId( null ) }
				/>
			) }
			<table className="form-table">
				<tbody>
					<tr>
						<th scope="row">Tool Configuration</th>
						<td>
							{ Object.keys( globalTools ).length > 0 ? (
								<div className="datamachine-tool-config-grid">
									{ Object.entries( globalTools ).map(
										( [ toolName, toolConfig ] ) => {
											const isConfigured =
												toolConfig.is_configured;
											const isEnabled =
												form.data.enabled_tools?.[
													toolName
												] ?? false;
											const toolLabel =
												toolConfig.label ||
												toolName.replace( /_/g, ' ' );

											return (
												<div
													key={ toolName }
													className="datamachine-tool-config-item"
												>
													<h4>{ toolLabel }</h4>
													{ toolConfig.description && (
														<p className="description">
															{
																toolConfig.description
															}
														</p>
													) }
													<div className="datamachine-tool-controls">
														<span
															className={ `datamachine-config-status ${
																isConfigured
																	? 'configured'
																	: 'not-configured'
															}` }
														>
															{ isConfigured
																? 'Configured'
																: 'Not Configured' }
														</span>

														{ toolConfig.requires_configuration && (
															<Button
																variant="secondary"
																onClick={ () =>
																	setOpenToolId(
																		toolName
																	)
																}
															>
																Configure
															</Button>
														) }

														{ isConfigured ? (
															<label className="datamachine-tool-enabled-toggle">
																<input
																	type="checkbox"
																	checked={
																		isEnabled
																	}
																	onChange={ (
																		e
																	) =>
																		handleToolToggle(
																			toolName,
																			e
																				.target
																				.checked
																		)
																	}
																/>
																Enable for
																agents
															</label>
														) : (
															<label className="datamachine-tool-enabled-toggle datamachine-tool-disabled">
																<input
																	type="checkbox"
																	disabled
																/>
																<span className="description">
																	Configure to
																	enable
																</span>
															</label>
														) }
													</div>
												</div>
											);
										}
									) }
								</div>
							) : (
								<p>No global tools are currently available.</p>
							) }
						</td>
					</tr>

					<tr>
						<th scope="row">
							<label htmlFor="global_system_prompt">
								Global System Prompt
							</label>
						</th>
						<td>
							<textarea
								id="global_system_prompt"
								rows="8"
								cols="70"
								className="large-text code"
								value={ form.data.global_system_prompt || '' }
								onChange={ ( e ) =>
									updateField(
										'global_system_prompt',
										e.target.value
									)
								}
							/>
							<p className="description">
								Primary system message that sets the tone and
								overall behavior for all AI agents. This is the
								first and most important instruction that
								influences every AI response in your workflows.
							</p>
						</td>
					</tr>

					<tr>
						<th scope="row">Default AI Provider &amp; Model</th>
						<td>
							<div className="datamachine-ai-provider-model-settings">
								<ProviderModelSelector
									provider={ form.data.default_provider }
									model={ form.data.default_model }
									onProviderChange={ handleProviderChange }
									onModelChange={ ( model ) =>
										updateField( 'default_model', model )
									}
									applyDefaults={ false }
									providerLabel="Default AI Provider"
									modelLabel="Default AI Model"
								/>
							</div>
							<p className="description">
								Set the default AI provider and model for new AI
								steps and chat requests. These can be overridden
								on a per-step or per-request basis.
							</p>
						</td>
					</tr>

					<tr>
						<th scope="row">Provide site context to agents</th>
						<td>
							<fieldset>
								<label htmlFor="site_context_enabled">
									<input
										type="checkbox"
										id="site_context_enabled"
										checked={
											form.data.site_context_enabled
										}
										onChange={ ( e ) =>
											updateField(
												'site_context_enabled',
												e.target.checked
											)
										}
									/>
									Include WordPress site context in AI
									requests
								</label>
								<p className="description">
									Automatically provides site information
									(post types, taxonomies, user stats) to AI
									agents for better context awareness.
								</p>
							</fieldset>
						</td>
					</tr>

					<tr>
						<th scope="row">
							<label htmlFor="max_turns">
								Maximum conversation turns
							</label>
						</th>
						<td>
							<input
								type="number"
								id="max_turns"
								value={ form.data.max_turns }
								onChange={ ( e ) =>
									updateField(
										'max_turns',
										Math.max(
											1,
											Math.min(
												50,
												parseInt(
													e.target.value,
													10
												) || 1
											)
										)
									)
								}
								min="1"
								max="50"
								className="small-text"
							/>
							<p className="description">
								Maximum number of conversation turns allowed for
								AI agents (1-50). Applies to both pipeline and
								chat conversations.
							</p>
						</td>
					</tr>
				</tbody>
			</table>

			<SettingsSaveBar
				hasChanges={ save.hasChanges }
				saveStatus={ save.saveStatus }
				onSave={ save.handleSave }
			/>
		</div>
	);
};

export default AgentTab;
