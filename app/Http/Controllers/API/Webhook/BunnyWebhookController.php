<?php

namespace App\Http\Controllers\API\Webhook;

use App\Http\Controllers\Controller;
use App\Jobs\FetchBunnyVideoDurationJob;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

class BunnyWebhookController extends Controller
{
    public function handle(Request $request)
    {
        // 1. Verify Signature
        $signature = $request->header('Webhook-Signature');
        if (empty($signature)) {
            return response()->json(['message' => 'Missing signature'], 401);
        }

        $secret = config('services.bunny.webhook_secret');
        if (empty($secret)) {
            Log::warning('Bunny webhook secret not configured. Rejecting webhook.');
            return response()->json(['message' => 'Secret not configured'], 500);
        }

        // Bunny signature formula: hash('sha256', VideoLibraryId + ApiKey)
        // Note: The "webhook_secret" in our config should be the Bunny API Key for the library, 
        // OR the user can configure a specific webhook secret if they use one. Bunny Stream specifically uses the API key for this hash.
        $libraryId = $request->input('VideoLibraryId');
        
        $expectedSignature = hash('sha256', $libraryId . $secret);

        if ($signature !== $expectedSignature) {
            Log::warning('Invalid Bunny webhook signature.');
            return response()->json(['message' => 'Invalid signature'], 401);
        }

        // 2. Process VideoFinished Event
        $status = $request->input('Status');
        if ($status !== 4) { // 4 usually means Finished in Bunny
            return response()->json(['message' => 'Ignored. Status not finished.']);
        }

        $videoGuid = $request->input('VideoGuid');
        $libraryId = $request->input('VideoLibraryId');

        if (!$videoGuid || !$libraryId) {
            return response()->json(['message' => 'Missing video or library ID'], 422);
        }

        // Find the lecture. Since we don't have a direct reverse lookup easily, we can find by content_url or just dispatch the job to figure it out.
        // Actually, we can just dispatch the job. BUT the job requires lectureId.
        // So we must find the lectureId by looking up `file` or `content_url` containing the videoGuid.
        $lecture = \App\Models\Course\CourseChapter\Lecture\CourseChapterLecture::where('type', 'video')
            ->where(function ($q) use ($videoGuid) {
                $q->where('file', 'like', "%{$videoGuid}%")
                  ->orWhere('youtube_url', 'like', "%{$videoGuid}%");
            })->first();

        if ($lecture) {
            // We can just fetch it again to be safe
            FetchBunnyVideoDurationJob::dispatch($lecture->id, $libraryId, $videoGuid);
            return response()->json(['message' => 'Job dispatched']);
        }

        return response()->json(['message' => 'Lecture not found'], 404);
    }
}
