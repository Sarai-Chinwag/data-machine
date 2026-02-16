<?php
/**
 * Image Generation Task for System Agent.
 *
 * Handles async image generation through Replicate API. Polls for prediction
 * status and handles completion, failure, or rescheduling for continued polling.
 *
 * @package DataMachine\Engine\AI\System\Tasks
 * @since 0.22.4
 */

namespace DataMachine\Engine\AI\System\Tasks;

defined( 'ABSPATH' ) || exit;

use DataMachine\Core\HttpClient;

class ImageGenerationTask extends SystemTask {

	/**
	 * Maximum attempts for polling (24 attempts = ~120 seconds with 5s intervals).
	 *
	 * @var int
	 */
	const MAX_ATTEMPTS = 24;

	/**
	 * Execute image generation task.
	 *
	 * Polls Replicate API once for prediction status. If still processing,
	 * reschedules for another check. If succeeded, downloads image and completes
	 * job. If failed, fails the job with error.
	 *
	 * @param int   $jobId  Job ID from DM Jobs table.
	 * @param array $params Task parameters containing prediction_id, api_key, etc.
	 */
	public function execute( int $jobId, array $params ): void {
		$prediction_id = $params['prediction_id'] ?? '';
		$api_key = $params['api_key'] ?? '';
		$model = $params['model'] ?? 'unknown';
		$prompt = $params['prompt'] ?? '';
		$aspect_ratio = $params['aspect_ratio'] ?? '';

		if ( empty( $prediction_id ) || empty( $api_key ) ) {
			$this->failJob( $jobId, 'Missing prediction_id or api_key in task parameters' );
			return;
		}

		// Set max attempts in engine_data if not already set
		$jobs_db = new \DataMachine\Core\Database\Jobs\Jobs();
		$job = $jobs_db->get_job( $jobId );
		$engine_data = $job['engine_data'] ?? [];
		if ( ! isset( $engine_data['max_attempts'] ) ) {
			$engine_data['max_attempts'] = self::MAX_ATTEMPTS;
			$jobs_db->store_engine_data( $jobId, $engine_data );
		}

		// Poll Replicate API for prediction status
		$result = HttpClient::get(
			"https://api.replicate.com/v1/predictions/{$prediction_id}",
			[
				'timeout' => 15,
				'headers' => [
					'Authorization' => 'Token ' . $api_key,
				],
				'context' => 'System Agent Image Generation Poll',
			]
		);

		if ( ! $result['success'] ) {
			// HTTP error - reschedule to try again
			do_action(
				'datamachine_log',
				'warning',
				"System Agent image generation HTTP error for job {$jobId}: " . ( $result['error'] ?? 'Unknown error' ),
				[
					'job_id'        => $jobId,
					'task_type'     => $this->getTaskType(),
					'agent_type'    => 'system',
					'prediction_id' => $prediction_id,
					'error'         => $result['error'] ?? 'Unknown HTTP error',
				]
			);

			$this->reschedule( $jobId, 5 ); // Try again in 5 seconds
			return;
		}

		$status_data = json_decode( $result['data'], true );
		$status = $status_data['status'] ?? '';

		switch ( $status ) {
			case 'succeeded':
				$this->handleSuccess( $jobId, $status_data, $model, $prompt, $aspect_ratio );
				break;

			case 'failed':
			case 'canceled':
				$error = $status_data['error'] ?? "Prediction {$status}";
				$this->failJob( $jobId, "Replicate prediction failed: {$error}" );
				break;

			case 'starting':
			case 'processing':
				// Still processing - reschedule for another check
				$this->reschedule( $jobId, 5 ); // Check again in 5 seconds
				break;

			default:
				$this->failJob( $jobId, "Unknown prediction status: {$status}" );
		}
	}

	/**
	 * Handle successful prediction completion.
	 *
	 * Downloads the generated image and completes the job with image URL.
	 *
	 * @param int    $jobId       Job ID.
	 * @param array  $statusData  Replicate prediction status data.
	 * @param string $model       Model used for generation.
	 * @param string $prompt      Original prompt.
	 * @param string $aspectRatio Original aspect ratio.
	 */
	private function handleSuccess( int $jobId, array $statusData, string $model, string $prompt, string $aspectRatio ): void {
		$output = $statusData['output'] ?? null;

		// Handle different output formats (string URL or array)
		$image_url = null;
		if ( is_string( $output ) ) {
			$image_url = $output;
		} elseif ( is_array( $output ) && ! empty( $output[0] ) ) {
			$image_url = $output[0];
		}

		if ( empty( $image_url ) ) {
			$this->failJob( $jobId, 'Replicate prediction succeeded but no image URL found in output' );
			return;
		}

		// Complete job with success data
		$result = [
			'success'      => true,
			'data'         => [
				'message'      => "Image generated successfully using {$model}.",
				'image_url'    => $image_url,
				'prompt'       => $prompt,
				'model'        => $model,
				'aspect_ratio' => $aspectRatio,
			],
			'tool_name'    => 'image_generation',
			'completed_at' => current_time( 'mysql' ),
		];

		$this->completeJob( $jobId, $result );
	}

	/**
	 * Get the task type identifier.
	 *
	 * @return string Task type identifier.
	 */
	public function getTaskType(): string {
		return 'image_generation';
	}
}