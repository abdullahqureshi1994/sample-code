<?php

namespace App\Http\Controllers\Api;

use App\Models\Conversation;
use App\Models\Project;
use App\Http\Controllers\Controller;
use Exception;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\Support\Facades\Log;

class AskMeAnythingApiController extends Controller
{
    public function result(Request $request)
    {
        $prompt = $request->prompt;
        $user = auth()->user();

        $project = Project::find($request->id);

        if (!$project) {
            return response()->json([
                "message" => "Invalid project",
                "status" => "error"
            ], 404);
        }

        if (!($user->id == $project->user->id)) {
            return response()->json([
                "message" => "Not authorized",
                "status" => "error"
            ], 403);
        }

        if (!$project->is_chat_active) {
            return response()->json([
                'message' => "The chat bot is not active yet.",
                "status" => "error"
            ], 403);
        }

        if (blank($prompt)) {
            return response()->json([
                'message' => "Missing prompt message.",
                "status" => "error"
            ], 400);
        }

        $isNewConversation = false;

        $conversation = null;
        if ($request->session_id) {
            $conversation = Conversation::find($request->session_id);
        }

        if (!$conversation) {
            $conversation = Conversation::create([
                'name' => $request->prompt,
                'project_id' => $project->id,
                'created_by' => $user ? $user->id : null,
                'session_id' => $request->session_id ? $request->session_id : Str::uuid()->toString()
            ]);
            $isNewConversation = true;
        }

        if ($promptHistory = $conversation->getHashedPromptHistory($prompt)) {
            return response()->json([
                'id' => $promptHistory->id,
                'created_at' => $promptHistory->created_at,
                'response' => $promptHistory->openai_response,
                'status' => 'success',
                'session_id' => $conversation->session_id
            ]);
        }

        $projectOwner = $project->user;
        $noQueryCreditsAlertMessage = 'You have exhausted your current query credits. Upgrade your plan to keep chatting with customGPT and experience personalized AI!';
        if (
            $projectOwner->currentTeam->subscribed() &&
            $projectOwner->currentTeam->sparkPlan()->id == env('STRIPE_PREMIUM_MONTHLY_ID')
        ) {
            $noQueryCreditsAlertMessage = 'You have exhausted your current query credits. Please contact hello@customgpt.ai for further assistance.';
        }

        if (!$projectOwner->haveQueryCredits()) {
            return response()->json([
                'status' => 'error',
                'message' => $noQueryCreditsAlertMessage,
                'session_id' => $conversation->session_id
            ], 403);
        }

        try {
            $answer = $conversation->sendMessage($prompt, $isNewConversation);

            return response()->json([
                'id' => $answer->id,
                'created_at' => $answer->created_at,
                'response' => $answer->openai_response,
                'status' => 'success',
                'session_id' => $conversation->session_id
            ]);
        } catch (Exception $e) {
            return response()->json([
                'message' => $e->getMessage(),
                'status' => 'error',
                'session_id' => $conversation->session_id
            ], 503);
        }
    }
}
