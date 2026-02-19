<?php

namespace App\Http\Controllers;

use App\Models\Course\Course;
use App\Models\PaymentTransaction;
use App\Services\HelperService;
use App\Services\ResponseService;
use Exception;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Razorpay\Api\Api;

class WebhookController extends Controller
{
    public function razorpay(Request $request)
    {
        try {
            // get the json data of payment
            $webhookBody = $request->getContent();
            $webhookBody = file_get_contents('php://input');
            $data = json_decode($webhookBody, false, 512, JSON_THROW_ON_ERROR);

            // Get Config Data From Settings
            $razorPayConfigData = HelperService::systemSettings([
                'razorpay_api_key',
                'razorpay_secret_key',
                'razorpay_webhook_secret_key',
            ]);
            $razorPayApiKey = $razorPayConfigData['razorpay_api_key'];
            $razorPaySecretKey = $razorPayConfigData['razorpay_secret_key'];
            $webhookSecret = $razorPayConfigData['razorpay_webhook_secret_key'];

            // gets the signature from header
            $webhookSignature = $request->header('X-Razorpay-Signature');

            //checks the signature
            $expectedSignature = hash_hmac('SHA256', $webhookBody, (string) $webhookSecret);

            // Initiate Razorpay Class
            $api = new Api($razorPayApiKey, $razorPaySecretKey);

            if ($expectedSignature == $webhookSignature) {
                $api->utility->verifyWebhookSignature($webhookBody, $webhookSignature, $webhookSecret);

                switch ($data->event) {
                    case 'payment.captured':
                        $entityData = $data->payload->payment->entity;
                        $transactionId = $entityData->id;
                        $paymentTransactionId = $entityData->notes->payment_transaction_id;
                        $response = $this->assignCourse($paymentTransactionId, $transactionId);
                        if ($response['error']) {
                            Log::error('Razorpay Webhook : ', [$response['message']]);
                        }
                        http_response_code(200);
                        break;
                    case 'payment.failed':
                        $entityData = $data->payload->payment->entity;
                        $paymentTransactionId = $entityData->notes->payment_transaction_id;
                        $response = $this->failedTransaction($paymentTransactionId);
                        if ($response['error']) {
                            Log::error('Razorpay Webhook : ', [$response['message']]);
                        }
                        http_response_code(200);
                        break;
                }

                Log::info('Payment Done Successfully');
            } else {
                Log::error('Razorpay Signature Not Matched Payment Failed !!!!!!');
            }
        } catch (Exception $e) {
            Log::error('Razorpay Webhook : Error occurred', [
                $e->getMessage() . ' --> ' . $e->getFile() . ' At Line : ' . $e->getLine(),
            ]);
            http_response_code(400);
            exit();
        }
    }

    /**
     * Success Business Login
     * @param $payment_transaction_id
     * @return array
     */
    private function assignCourse($paymentTransactionId, $transactionId)
    {
        try {
            $paymentTransactionData = PaymentTransaction::where('id', $paymentTransactionId)->first();
            if ($paymentTransactionData == null) {
                Log::error('Payment Transaction id not found');
                ResponseService::logError('Payment Transaction id not found');
                exit();
            }

            if ($paymentTransactionData->payment_status == 'succeed') {
                Log::info('Transaction Already Succeed');
                ResponseService::logError('Transaction Already Succeed');
                exit();
            }

            DB::beginTransaction();
            $paymentTransactionData->update(['transaction_id' => $transactionId, 'payment_status' => 'success']);

            $courseId = $paymentTransactionData->course_id;
            $userId = $paymentTransactionData->user_id;

            $course = Course::findOrFail($courseId);

            if (!empty($course)) {
                // Course tracking will be handled via user_curriculum_trackings when user interacts with curriculum items
                // No need to create UserCourseTrack entries
                // The order completion itself grants access to the courses
            }
            DB::commit();
            ResponseService::successResponse('Transaction Verified Successfully');
        } catch (Exception $e) {
            DB::rollBack();
            Log::error($e->getMessage() . 'WebhookController -> assignCourse');
            exit();
        }
    }

    /**
     * Failed Business Logic
     * @param $paymentTransactionId
     * @return array
     */
    private function failedTransaction($paymentTransactionId)
    {
        try {
            $paymentTransactionData = PaymentTransaction::find($paymentTransactionId);
            if (!$paymentTransactionData) {
                Log::error('Payment Transaction id not found');
                ResponseService::logError('Payment Transaction id not found');
                exit();
            }

            if ($paymentTransactionData->payment_status == 'failed') {
                Log::info('Transaction Already Failed');
                ResponseService::logError('Transaction Already Failed');
                exit();
            }

            DB::beginTransaction();
            $paymentTransactionData->update(['payment_status' => 'failed']);
            DB::commit();
            ResponseService::successResponse('Transaction Failed Successfully');
        } catch (Exception $e) {
            DB::rollBack();
            Log::error($e->getMessage() . 'WebhookController -> failedTransaction');
            exit();
        }
    }
}
