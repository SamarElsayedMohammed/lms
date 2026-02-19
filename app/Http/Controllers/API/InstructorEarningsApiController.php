<?php

namespace App\Http\Controllers\API;

use App\Http\Controllers\Controller;
use App\Models\Course\UserCourseTrack;
use App\Models\WithdrawalRequest;
use App\Services\ApiResponseService;
use App\Services\EarningsService;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

final class InstructorEarningsApiController extends Controller
{
    public function __construct(
        private readonly EarningsService $earningsService,
    ) {}

    /**
     * Get instructor earnings dashboard data
     */
    public function getInstructorEarnings(Request $request)
    {
        try {
            // In single instructor mode, return error
            if (\App\Services\InstructorModeService::isSingleInstructorMode()) {
                return ApiResponseService::validationError(
                    'Instructor earnings are disabled in Single Instructor mode.',
                );
            }

            $user = Auth::user();
            if (!$user) {
                return ApiResponseService::unauthorizedResponse('User not authenticated');
            }

            // Check if user is an instructor
            if (!$user->hasRole(config('constants.SYSTEM_ROLES.INSTRUCTOR'))) {
                return ApiResponseService::validationError('User is not an instructor');
            }

            $instructor = $user->instructor_details;
            if (!$instructor) {
                return ApiResponseService::validationError('Instructor details not found');
            }

            // Get date range (default to current year)
            $year = (int) $request->get('year', date('Y'));

            // Get totals using EarningsService
            $stats = $this->earningsService->getStats(instructorId: $user->id);
            $totalRevenue = $stats['revenue'];
            $totalAdminCommission = $stats['commission'];
            $totalEarning = $stats['earnings'];

            // Calculate available to withdraw using EarningsService
            $totalWithdrawn = $this->earningsService->getInstructorTotalWithdrawn($user->id);
            $availableToWithdraw = $this->earningsService->getInstructorAvailableBalance($user->id);

            // Get raw data from EarningsService
            $monthlyData = $this->earningsService->getMonthlyData($year, $user->id);
            $dailyDataForMonth = $this->earningsService->getDailyDataForMonth($year, now()->month, $user->id);
            $dailyDataForWeek = $this->earningsService->getDailyDataForWeek($year, now()->weekOfYear, $user->id);

            // Format summary card chart data
            $revenueChartData = array_map(static fn($m) => [
                'name' => $m['month'],
                'earning' => $m['revenue'],
            ], $monthlyData);
            $commissionChartData = array_map(static fn($m) => [
                'name' => $m['month'],
                'earning' => $m['commission'],
            ], $monthlyData);
            $earningChartData = array_map(static fn($m) => [
                'name' => $m['month'],
                'earning' => $m['earnings'],
            ], $monthlyData);

            // Format revenue chart data (yearly/monthly/weekly)
            $revenueChartYearly = array_map(static fn($m) => [
                'name' => $m['month'],
                'revenue' => $m['revenue'],
                'commission' => $m['commission'],
            ], $monthlyData);

            $revenueChartMonthly = array_map(static fn($d) => [
                'name' => $d['day'],
                'revenue' => $d['revenue'],
                'commission' => $d['commission'],
            ], $dailyDataForMonth);

            $revenueChartWeekly = array_map(static fn($d) => [
                'name' => $d['day_name'],
                'revenue' => $d['revenue'],
                'commission' => $d['commission'],
            ], $dailyDataForWeek);

            // Format earnings chart data (yearly/monthly/weekly)
            $earningsChartYearly = array_map(static fn($m) => [
                'name' => $m['month'],
                'earning' => $m['earnings'],
            ], $monthlyData);
            $earningsChartMonthly = array_map(static fn($d) => [
                'name' => $d['day'],
                'earning' => $d['earnings'],
            ], $dailyDataForMonth);
            $earningsChartWeekly = array_map(static fn($d) => [
                'name' => $d['day_name'],
                'earning' => $d['earnings'],
            ], $dailyDataForWeek);

            // Get recent withdrawal requests
            $recentWithdrawals = WithdrawalRequest::where('user_id', $user->id)
                ->orderBy('created_at', 'desc')
                ->limit(5)
                ->get()
                ->map(static fn($withdrawal) => [
                    'id' => $withdrawal->id,
                    'amount' => $withdrawal->amount,
                    'status' => $withdrawal->status,
                    'requested_at' => $withdrawal->created_at->format('Y-m-d H:i:s'),
                    'processed_at' => $withdrawal->updated_at->format('Y-m-d H:i:s'),
                    'status_label' => ucfirst((string) $withdrawal->status),
                ]);

            // Prepare response data
            $responseData = [
                'summary_cards' => [
                    'total_revenue' => [
                        'value' => number_format($totalRevenue, 2),
                        'formatted_value' => number_format($totalRevenue, 2),
                        'chartData' => $revenueChartData,
                    ],
                    'total_commission' => [
                        'value' => number_format($totalAdminCommission, 2),
                        'formatted_value' => number_format($totalAdminCommission, 2),
                        'chartData' => $commissionChartData,
                    ],
                    'total_earning' => [
                        'value' => number_format($totalEarning, 2),
                        'formatted_value' => number_format($totalEarning, 2),
                        'chartData' => $earningChartData,
                    ],
                ],
                'action_cards' => [
                    'available_to_withdraw' => [
                        'value' => number_format($availableToWithdraw, 2),
                        'formatted_value' => number_format($availableToWithdraw, 2),
                        'button_text' => 'Withdraw →',
                        'button_action' => 'withdraw',
                    ],
                    'total_withdrawal' => [
                        'value' => number_format($totalWithdrawn, 2),
                        'formatted_value' => number_format($totalWithdrawn, 2),
                        'button_text' => 'View History →',
                        'button_action' => 'view_history',
                    ],
                ],
                'charts' => [
                    'revenue_chart' => [
                        'yearly' => $revenueChartYearly,
                        'monthly' => $revenueChartMonthly,
                        'weekly' => $revenueChartWeekly,
                    ],
                    'earnings_chart' => [
                        'yearly' => $earningsChartYearly,
                        'monthly' => $earningsChartMonthly,
                        'weekly' => $earningsChartWeekly,
                    ],
                ],
                'recent_withdrawals' => $recentWithdrawals,
                'filters' => [
                    'year' => $year,
                    'available_years' => range(date('Y') - 5, date('Y')),
                ],
            ];

            return ApiResponseService::successResponse(
                'Instructor earnings data retrieved successfully',
                $responseData,
            );
        } catch (\Exception $e) {
            \Illuminate\Support\Facades\Log::error('Error getting instructor earnings: ' . $e->getMessage());
            return ApiResponseService::errorResponse('Failed to load earnings data: ' . $e->getMessage());
        }
    }

    /**
     * Get withdrawal details with pagination
     */
    public function getWithdrawalDetails(Request $request)
    {
        try {
            $user = Auth::user();
            if (!$user) {
                return ApiResponseService::unauthorizedResponse('User not authenticated');
            }

            if (!$user->hasRole(config('constants.SYSTEM_ROLES.INSTRUCTOR'))) {
                return ApiResponseService::validationError('User is not an instructor');
            }

            $instructor = $user->instructor_details;
            if (!$instructor) {
                return ApiResponseService::validationError('Instructor details not found');
            }

            // Get pagination parameters
            $perPage = $request->get('per_page', 10);
            $page = $request->get('page', 1);
            $search = $request->get('search', '');

            // Validate per_page parameter (max 50 records per page)
            if ($perPage > 50) {
                $perPage = 50;
            }

            // Ensure per_page is at least 1 to avoid division by zero
            if ($perPage < 1) {
                $perPage = 10;
            }

            // Calculate summary data using EarningsService
            $totalWithdrawn = $this->earningsService->getInstructorTotalWithdrawn($user->id);
            $availableToWithdraw = $this->earningsService->getInstructorAvailableBalance($user->id);

            // Build query with search
            $query = WithdrawalRequest::where('user_id', $user->id);

            if (!empty($search)) {
                $query->where(static function ($q) use ($search): void {
                    $q
                        ->where('id', 'LIKE', "%{$search}%")
                        ->orWhere('amount', 'LIKE', "%{$search}%")
                        ->orWhere('status', 'LIKE', "%{$search}%")
                        ->orWhere('account_holder_name', 'LIKE', "%{$search}%")
                        ->orWhere('account_number', 'LIKE', "%{$search}%")
                        ->orWhere('bank_name', 'LIKE', "%{$search}%");
                });
            }

            // Get withdrawal requests with pagination
            $withdrawals = $query->orderBy('created_at', 'desc')->paginate($perPage, ['*'], 'page', $page);

            // Format withdrawal data
            $withdrawalData = $withdrawals->map(static function ($withdrawal, $index) {
                $statusColors = [
                    'pending' => '#3B82F6', // Blue
                    'approved' => '#10B981', // Green
                    'successful' => '#10B981', // Green
                    'failed' => '#EF4444', // Red
                    'rejected' => '#EF4444', // Red
                ];

                $statusLabels = [
                    'pending' => 'Pending',
                    'approved' => 'Successful',
                    'successful' => 'Successful',
                    'failed' => 'Failed',
                    'rejected' => 'Failed',
                ];

                return [
                    'id' => $withdrawal->id,
                    'transaction_id' => $withdrawal->id, // Using ID as transaction ID
                    'transaction_date' => $withdrawal->created_at->format('d F, Y'),
                    'amount' => $withdrawal->amount,
                    'formatted_amount' => number_format($withdrawal->amount, 0),
                    'status' => $withdrawal->status,
                    'status_label' => $statusLabels[$withdrawal->status] ?? ucfirst((string) $withdrawal->status),
                    'status_color' => $statusColors[$withdrawal->status] ?? '#6B7280',
                    'requested_at' => $withdrawal->created_at->format('Y-m-d H:i:s'),
                    'processed_at' => $withdrawal->updated_at->format('Y-m-d H:i:s'),
                    'notes' => $withdrawal->notes,
                    'bank_details' => [
                        'account_holder_name' => $withdrawal->account_holder_name,
                        'account_number' => $withdrawal->account_number,
                        'bank_name' => $withdrawal->bank_name,
                        'routing_number' => $withdrawal->routing_number,
                    ],
                ];
            });

            // Create pagination links
            $lastPage = $withdrawals->lastPage();
            $baseUrl = request()->url();
            $path = str_replace(request()->root(), '', $baseUrl);

            // Build query parameters for URLs
            $queryParams = request()->query();
            unset($queryParams['page']); // Remove page from query params

            $firstPageUrl = $baseUrl . '?' . http_build_query(array_merge($queryParams, ['page' => 1]));
            $lastPageUrl = $baseUrl . '?' . http_build_query(array_merge($queryParams, ['page' => $lastPage]));
            $nextPageUrl = $page < $lastPage
                ? $baseUrl . '?' . http_build_query(array_merge($queryParams, ['page' => $page + 1]))
                : null;
            $prevPageUrl = $page > 1
                ? $baseUrl . '?' . http_build_query(array_merge($queryParams, ['page' => $page - 1]))
                : null;

            // Create pagination links array
            $links = [];

            // Previous link
            $links[] = [
                'url' => $prevPageUrl,
                'label' => '&laquo; Previous',
                'active' => false,
            ];

            // Page number links
            for ($i = 1; $i <= $lastPage; $i++) {
                $pageUrl = $baseUrl . '?' . http_build_query(array_merge($queryParams, ['page' => $i]));
                $links[] = [
                    'url' => $pageUrl,
                    'label' => (string) $i,
                    'active' => $i == $page,
                ];
            }

            // Next link
            $links[] = [
                'url' => $nextPageUrl,
                'label' => 'Next &raquo;',
                'active' => false,
            ];

            $responseData = [
                'current_page' => (int) $page,
                'data' => $withdrawalData,
                'first_page_url' => $firstPageUrl,
                'from' => $withdrawals->firstItem(),
                'last_page' => $lastPage,
                'last_page_url' => $lastPageUrl,
                'links' => $links,
                'next_page_url' => $nextPageUrl,
                'path' => $path,
                'per_page' => (int) $perPage,
                'prev_page_url' => $prevPageUrl,
                'to' => $withdrawals->lastItem(),
                'total' => $withdrawals->total(),
                'summary_cards' => [
                    'total_withdrawal' => [
                        'value' => number_format($totalWithdrawn, 2),
                        'formatted_value' => number_format($totalWithdrawn, 2),
                        'icon' => 'withdrawal-icon',
                    ],
                    'available_to_withdraw' => [
                        'value' => number_format($availableToWithdraw, 2),
                        'formatted_value' => number_format($availableToWithdraw, 2),
                        'icon' => 'withdraw-icon',
                    ],
                ],
                'filters' => [
                    'search' => $search,
                    'per_page_options' => [10, 25, 50],
                ],
            ];

            return ApiResponseService::successResponse('Withdrawal details retrieved successfully', $responseData);
        } catch (\Exception $e) {
            \Illuminate\Support\Facades\Log::error('Error getting withdrawal details: ' . $e->getMessage());
            return ApiResponseService::errorResponse('Failed to load withdrawal details: ' . $e->getMessage());
        }
    }

    /**
     * Get withdrawal history (legacy method)
     */
    public function getWithdrawalHistory(Request $request)
    {
        try {
            $user = Auth::user();
            if (!$user) {
                return ApiResponseService::unauthorizedResponse('User not authenticated');
            }

            if (!$user->hasRole(config('constants.SYSTEM_ROLES.INSTRUCTOR'))) {
                return ApiResponseService::validationError('User is not an instructor');
            }

            $instructor = $user->instructor_details;
            if (!$instructor) {
                return ApiResponseService::validationError('Instructor details not found');
            }

            // Get pagination parameters
            $perPage = $request->get('per_page', 15);
            $page = $request->get('page', 1);

            // Get withdrawal requests
            $withdrawals = WithdrawalRequest::where('user_id', $user->id)
                ->orderBy('created_at', 'desc')
                ->paginate($perPage, ['*'], 'page', $page);

            // Calculate withdrawal summary using EarningsService
            $totalWithdrawn = $this->earningsService->getInstructorTotalWithdrawn($user->id);
            $availableToWithdraw = $this->earningsService->getInstructorAvailableBalance($user->id);

            $withdrawalData = $withdrawals->map(static function ($withdrawal) {
                $paymentDetails = $withdrawal->payment_details ?? [];
                return [
                    'id' => $withdrawal->id,
                    'amount' => $withdrawal->amount,
                    'formatted_amount' => number_format($withdrawal->amount, 2),
                    'status' => $withdrawal->status,
                    'status_label' => ucfirst((string) $withdrawal->status),
                    'requested_at' => $withdrawal->created_at->format('Y-m-d H:i:s'),
                    'processed_at' => $withdrawal->updated_at->format('Y-m-d H:i:s'),
                    'notes' => $withdrawal->notes,
                    'payment_method' => $withdrawal->payment_method,
                    'bank_details' => [
                        'account_holder_name' => $paymentDetails['account_holder_name'] ?? null,
                        'account_number' => $paymentDetails['account_number'] ?? null,
                        'bank_name' => $paymentDetails['bank_name'] ?? null,
                        'routing_number' => $paymentDetails['routing_number'] ?? $paymentDetails['ifsc_code'] ?? null,
                    ],
                ];
            });

            return ApiResponseService::successResponse('Withdrawal history retrieved successfully', [
                'withdrawals' => $withdrawalData,
                'available_to_withdraw' => [
                    'value' => number_format($availableToWithdraw, 2),
                    'formatted_value' => number_format($availableToWithdraw, 2),
                ],
                'total_withdrawal' => [
                    'value' => number_format($totalWithdrawn, 2),
                    'formatted_value' => number_format($totalWithdrawn, 2),
                ],
                'pagination' => [
                    'current_page' => $withdrawals->currentPage(),
                    'per_page' => $withdrawals->perPage(),
                    'total' => $withdrawals->total(),
                    'last_page' => $withdrawals->lastPage(),
                    'from' => $withdrawals->firstItem(),
                    'to' => $withdrawals->lastItem(),
                    'has_more_pages' => $withdrawals->hasMorePages(),
                ],
            ]);
        } catch (\Exception $e) {
            \Illuminate\Support\Facades\Log::error('Error getting withdrawal history: ' . $e->getMessage());
            return ApiResponseService::errorResponse('Failed to load withdrawal history: ' . $e->getMessage());
        }
    }

    /**
     * Request withdrawal
     */
    public function requestWithdrawal(Request $request)
    {
        try {
            $user = Auth::user();
            if (!$user) {
                return ApiResponseService::unauthorizedResponse('User not authenticated');
            }

            if (!$user->hasRole(config('constants.SYSTEM_ROLES.INSTRUCTOR'))) {
                return ApiResponseService::validationError('User is not an instructor');
            }

            $instructor = $user->instructor_details;
            if (!$instructor) {
                return ApiResponseService::validationError('Instructor details not found');
            }

            $validator = \Illuminate\Support\Facades\Validator::make($request->all(), [
                'amount' => 'required|numeric|min:1',
                'account_holder_name' => 'required|string|max:255',
                'account_number' => 'required|string|max:50',
                'bank_name' => 'required|string|max:255',
                'routing_number' => 'required|string|max:50',
                'notes' => 'nullable|string|max:500',
            ]);

            if ($validator->fails()) {
                return ApiResponseService::validationError($validator->errors()->first());
            }

            // Check if amount is available using EarningsService
            $availableToWithdraw = $this->earningsService->getInstructorAvailableBalance($user->id);

            if ($request->amount > $availableToWithdraw) {
                return ApiResponseService::validationError('Insufficient balance. Available to withdraw: '
                . number_format($availableToWithdraw, 2));
            }

            // Create withdrawal request
            $withdrawal = WithdrawalRequest::create([
                'user_id' => $user->id,
                'amount' => $request->amount,
                'account_holder_name' => $request->account_holder_name,
                'account_number' => $request->account_number,
                'bank_name' => $request->bank_name,
                'routing_number' => $request->routing_number,
                'notes' => $request->notes,
                'status' => 'pending',
            ]);

            return ApiResponseService::successResponse('Withdrawal request submitted successfully', [
                'withdrawal_id' => $withdrawal->id,
                'amount' => $withdrawal->amount,
                'formatted_amount' => number_format($withdrawal->amount, 2),
                'status' => $withdrawal->status,
            ]);
        } catch (\Exception $e) {
            \Illuminate\Support\Facades\Log::error('Error requesting withdrawal: ' . $e->getMessage());
            return ApiResponseService::errorResponse('Failed to submit withdrawal request: ' . $e->getMessage());
        }
    }

    /**
     * Get instructor sales statistics (yearly, monthly, weekly)
     */
    public function getInstructorSalesStatistics(Request $request)
    {
        try {
            $user = Auth::user();
            if (!$user) {
                return ApiResponseService::unauthorizedResponse('User not authenticated');
            }

            if (!$user->hasRole(config('constants.SYSTEM_ROLES.INSTRUCTOR'))) {
                return ApiResponseService::validationError('User is not an instructor');
            }

            $instructor = $user->instructor_details;
            if (!$instructor) {
                return ApiResponseService::validationError('Instructor details not found');
            }

            $validator = \Illuminate\Support\Facades\Validator::make($request->all(), [
                'period' => 'nullable|in:yearly,monthly,weekly',
                'year' => 'nullable|integer|min:2020|max:' . (date('Y') + 1),
                'month' => 'nullable|integer|min:1|max:12',
                'week' => 'nullable|integer|min:1|max:53',
            ]);

            if ($validator->fails()) {
                return ApiResponseService::validationError($validator->errors()->first());
            }

            $period = $request->get('period', 'yearly');
            $year = (int) $request->get('year', date('Y'));
            $month = (int) $request->get('month', date('n'));
            $week = (int) $request->get('week', date('W'));

            // Get raw data from service and format for this API
            $data = match ($period) {
                'yearly' => array_map(
                    static fn($m) => [
                        'period' => $m['month'],
                        'period_number' => $m['month_number'],
                        'revenue' => $m['revenue'],
                        'commission' => $m['commission'],
                        'earnings' => $m['earnings'],
                        'formatted_revenue' => number_format($m['revenue'], 0),
                        'formatted_commission' => number_format($m['commission'], 0),
                        'formatted_earnings' => number_format($m['earnings'], 0),
                    ],
                    $this->earningsService->getMonthlyData($year, $user->id),
                ),
                'monthly' => array_map(
                    static fn($d) => [
                        'period' => 'Day ' . $d['day'],
                        'period_number' => $d['day'],
                        'revenue' => $d['revenue'],
                        'commission' => $d['commission'],
                        'earnings' => $d['earnings'],
                        'formatted_revenue' => number_format($d['revenue'], 0),
                        'formatted_commission' => number_format($d['commission'], 0),
                        'formatted_earnings' => number_format($d['earnings'], 0),
                    ],
                    $this->earningsService->getDailyDataForMonth($year, $month, $user->id),
                ),
                'weekly' => array_map(
                    static fn($d) => [
                        'period' => $d['day_name'],
                        'period_number' => $d['day'],
                        'revenue' => $d['revenue'],
                        'commission' => $d['commission'],
                        'earnings' => $d['earnings'],
                        'formatted_revenue' => number_format($d['revenue'], 0),
                        'formatted_commission' => number_format($d['commission'], 0),
                        'formatted_earnings' => number_format($d['earnings'], 0),
                    ],
                    $this->earningsService->getDailyDataForWeek($year, $week, $user->id),
                ),
                default => [],
            };

            return ApiResponseService::successResponse('Sales statistics retrieved successfully', [
                'period' => $period,
                'year' => $year,
                'month' => $month,
                'week' => $week,
                'data' => $data,
                'summary' => $this->calculateSalesSummary($data),
            ]);
        } catch (\Exception $e) {
            \Illuminate\Support\Facades\Log::error('Error getting sales statistics: ' . $e->getMessage());
            return ApiResponseService::errorResponse('Failed to load sales statistics: ' . $e->getMessage());
        }
    }

    /**
     * Calculate sales summary statistics
     */
    private function calculateSalesSummary(array $data): array
    {
        $totalRevenue = array_sum(array_column($data, 'revenue'));
        $totalCommission = array_sum(array_column($data, 'commission'));
        $totalEarnings = array_sum(array_column($data, 'earnings'));

        $avgRevenue = count($data) > 0 ? $totalRevenue / count($data) : 0;
        $avgCommission = count($data) > 0 ? $totalCommission / count($data) : 0;
        $avgEarnings = count($data) > 0 ? $totalEarnings / count($data) : 0;

        $maxRevenue = max(array_column($data, 'revenue'));
        $maxCommission = max(array_column($data, 'commission'));
        $maxEarnings = max(array_column($data, 'earnings'));

        $minRevenue = min(array_column($data, 'revenue'));
        $minCommission = min(array_column($data, 'commission'));
        $minEarnings = min(array_column($data, 'earnings'));

        return [
            'totals' => [
                'revenue' => $totalRevenue,
                'commission' => $totalCommission,
                'earnings' => $totalEarnings,
                'formatted_revenue' => number_format($totalRevenue, 2),
                'formatted_commission' => number_format($totalCommission, 2),
                'formatted_earnings' => number_format($totalEarnings, 2),
            ],
            'averages' => [
                'revenue' => $avgRevenue,
                'commission' => $avgCommission,
                'earnings' => $avgEarnings,
                'formatted_revenue' => number_format($avgRevenue, 2),
                'formatted_commission' => number_format($avgCommission, 2),
                'formatted_earnings' => number_format($avgEarnings, 2),
            ],
            'maximums' => [
                'revenue' => $maxRevenue,
                'commission' => $maxCommission,
                'earnings' => $maxEarnings,
                'formatted_revenue' => number_format($maxRevenue, 2),
                'formatted_commission' => number_format($maxCommission, 2),
                'formatted_earnings' => number_format($maxEarnings, 2),
            ],
            'minimums' => [
                'revenue' => $minRevenue,
                'commission' => $minCommission,
                'earnings' => $minEarnings,
                'formatted_revenue' => number_format($minRevenue, 2),
                'formatted_commission' => number_format($minCommission, 2),
                'formatted_earnings' => number_format($minEarnings, 2),
            ],
        ];
    }

    /**
     * Get course analysis for instructor
     */
    public function getCourseAnalysis(Request $request)
    {
        try {
            $user = Auth::user();
            if (!$user) {
                return ApiResponseService::unauthorizedResponse('User not authenticated');
            }

            // Check if user is an instructor
            if (!$user->hasRole(config('constants.SYSTEM_ROLES.INSTRUCTOR'))) {
                return ApiResponseService::validationError('User is not an instructor');
            }

            $instructor = $user->instructor_details;
            if (!$instructor) {
                return ApiResponseService::validationError('Instructor details not found');
            }

            $validator = \Illuminate\Support\Facades\Validator::make($request->all(), [
                'course_id' => 'nullable|exists:courses,id',
                'course_slug' => 'nullable|exists:courses,slug',
            ]);

            if ($validator->fails()) {
                return ApiResponseService::validationError($validator->errors()->first());
            }

            // Custom validation to ensure either course_id or course_slug is provided, but not both
            if (!$request->has('course_id') && !$request->has('course_slug')) {
                return ApiResponseService::validationError('Either course_id or course_slug must be provided');
            }

            if ($request->has('course_id') && $request->has('course_slug')) {
                return ApiResponseService::validationError('Provide either course_id or course_slug, not both');
            }

            // Determine course ID
            $courseId = $request->course_id;
            if ($request->has('course_slug')) {
                $course = \App\Models\Course\Course::where('slug', $request->course_slug)->first();
                if (!$course) {
                    return ApiResponseService::validationError('Course not found');
                }
                $courseId = $course->id;
            }

            // Get course details
            $course = \App\Models\Course\Course::where('id', $courseId)->where('user_id', $user->id)->first();

            if (!$course) {
                return ApiResponseService::validationError(
                    'Course not found or you do not have permission to view this course',
                );
            }

            // Get course statistics
            $courseStats = $this->getCourseStatistics($courseId, $user->id);

            // Get revenue and earnings chart data
            $revenueChartData = $this->getCourseRevenueChartData($courseId, $user->id);
            $earningsChartData = $this->getCourseEarningsChartData($courseId, $user->id);

            // Get average rating and total ratings count
            $averageRating =
                \App\Models\Rating::where('rateable_id', $courseId)->where(
                    'rateable_type',
                    \App\Models\Course\Course::class,
                )->avg('rating') ?? 0;
            $averageRating = round($averageRating, 2);

            $totalRatingsCount = \App\Models\Rating::where('rateable_id', $courseId)
                ->where('rateable_type', \App\Models\Course\Course::class)
                ->count();

            $responseData = [
                'course_info' => [
                    'slug' => $course->slug,
                    'title' => $course->title,
                    'short_description' => $course->short_description,
                    'thumbnail' => $course->thumbnail ? asset('storage/' . $course->thumbnail) : null,
                    'average_rating' => $averageRating,
                    'total_ratings_count' => $totalRatingsCount,
                    'price' => (float) $course->price,
                    'discount_price' => $course->discount_price ? (float) $course->discount_price : null,
                ],
                'summary_cards' => $courseStats,
                'revenue_chart' => $revenueChartData,
                'earnings_chart' => $earningsChartData,
            ];

            return ApiResponseService::successResponse('Course analysis retrieved successfully', $responseData);
        } catch (\Exception $e) {
            \Illuminate\Support\Facades\Log::error('Error getting course analysis: ' . $e->getMessage());
            return ApiResponseService::errorResponse('Failed to load course analysis: ' . $e->getMessage());
        }
    }

    /**
     * Get course statistics
     */
    private function getCourseStatistics($courseId, $instructorId)
    {
        $year = now()->year;
        $monthNames = ['Jan', 'Feb', 'Mar', 'Apr', 'May', 'Jun', 'Jul', 'Aug', 'Sep', 'Oct', 'Nov', 'Dec'];

        // Total enrollments
        $totalEnrollments = UserCourseTrack::where('course_id', $courseId)->count();

        // Completed enrollments (using status column)
        $completedEnrollments = UserCourseTrack::where('course_id', $courseId)->where('status', 'completed')->count();

        // Completion rate
        $completionRate = $totalEnrollments > 0 ? round(($completedEnrollments / $totalEnrollments) * 100, 2) : 0;

        // Average rating (using polymorphic relationship)
        $averageRating =
            \App\Models\Rating::where('rateable_id', $courseId)->where(
                'rateable_type',
                \App\Models\Course\Course::class,
            )->avg('rating') ?? 0;
        $averageRating = round($averageRating, 2);

        // Total ratings count
        $totalRatings = \App\Models\Rating::where('rateable_id', $courseId)
            ->where('rateable_type', \App\Models\Course\Course::class)
            ->count();

        // Recent enrollments (last 30 days)
        $recentEnrollments = UserCourseTrack::where('course_id', $courseId)
            ->where('created_at', '>=', now()->subDays(30))
            ->count();

        // Calculate financial stats using EarningsService
        $stats = $this->earningsService->getStats(
            $instructorId,
            $courseId,
            Carbon::createFromDate($year, 1, 1)->startOfYear(),
            Carbon::createFromDate($year, 12, 31)->endOfYear(),
        );
        $totalRevenue = $stats['revenue'];
        $totalCommission = $stats['commission'];
        $totalEarning = $stats['earnings'];

        // Get monthly chart data using EarningsService
        $monthlyData = $this->earningsService->getMonthlyData($year, $instructorId, $courseId);

        $revenueChartData = [];
        $commissionChartData = [];
        $earningChartData = [];

        foreach ($monthlyData as $monthStats) {
            $revenueChartData[] = [
                'name' => $monthStats['month'],
                'earning' => (float) $monthStats['revenue'],
            ];

            $commissionChartData[] = [
                'name' => $monthStats['month'],
                'earning' => (float) $monthStats['commission'],
            ];

            $earningChartData[] = [
                'name' => $monthStats['month'],
                'earning' => (float) $monthStats['earnings'],
            ];
        }

        return [
            'total_revenue' => [
                'value' => number_format($totalRevenue, 2),
                'formatted_value' => number_format($totalRevenue, 2),
                'chartData' => $revenueChartData,
            ],
            'total_commission' => [
                'value' => number_format($totalCommission, 2),
                'formatted_value' => number_format($totalCommission, 2),
                'chartData' => $commissionChartData,
            ],
            'total_earning' => [
                'value' => number_format($totalEarning, 2),
                'formatted_value' => number_format($totalEarning, 2),
                'chartData' => $earningChartData,
            ],
        ];
    }

    /**
     * Get enrollment data over time
     */
    private function getEnrollmentData($courseId)
    {
        $enrollments = UserCourseTrack::where('course_id', $courseId)
            ->selectRaw('DATE(created_at) as date, COUNT(*) as count')
            ->where('created_at', '>=', now()->subDays(30))
            ->groupBy('date')
            ->orderBy('date')
            ->get();

        $data = [];
        for ($i = 29; $i >= 0; $i--) {
            $date = now()->subDays($i)->format('Y-m-d');
            $enrollment = $enrollments->where('date', $date)->first();
            $data[] = [
                'date' => $date,
                'enrollments' => $enrollment ? $enrollment->count : 0,
                'formatted_date' => now()->subDays($i)->format('M d'),
            ];
        }

        return $data;
    }

    /**
     * Get course revenue data
     */
    private function getCourseRevenueData($courseId, $instructorId)
    {
        // Monthly revenue for last 12 months - use stats for each month
        $revenueData = [];
        for ($i = 11; $i >= 0; $i--) {
            $targetDate = now()->subMonths($i);
            $startOfMonth = $targetDate->copy()->startOfMonth();
            $endOfMonth = $targetDate->copy()->endOfMonth();

            $stats = $this->earningsService->getStats($instructorId, $courseId, $startOfMonth, $endOfMonth);

            $revenueData[] = [
                'month' => $startOfMonth->format('M Y'),
                'revenue' => (float) $stats['revenue'],
                'formatted_revenue' => number_format($stats['revenue'], 0),
            ];
        }

        return $revenueData;
    }

    /**
     * Get course revenue chart data with yearly, monthly, weekly options
     */
    private function getCourseRevenueChartData($courseId, $instructorId)
    {
        $currentYear = now()->year;

        return [
            'yearly' => $this->getCourseYearlyRevenueData($courseId, $currentYear),
            'monthly' => $this->getCourseMonthlyRevenueData($courseId, $currentYear, now()->month),
            'weekly' => $this->getCourseWeeklyRevenueData($courseId, $currentYear, now()->weekOfYear),
        ];
    }

    /**
     * Get yearly revenue data for course (12 months)
     */
    private function getCourseYearlyRevenueData($courseId, $year)
    {
        $monthlyData = $this->earningsService->getMonthlyData($year, null, $courseId);

        return array_map(static fn($stats) => [
            'name' => $stats['month'],
            'revenue' => (float) $stats['revenue'],
            'commission' => (float) $stats['commission'],
        ], $monthlyData);
    }

    /**
     * Get monthly revenue data for course (30 days)
     */
    private function getCourseMonthlyRevenueData($courseId, $year, $month)
    {
        $dailyData = $this->earningsService->getDailyDataForMonth($year, $month, null, $courseId);

        return array_map(static fn($stats) => [
            'name' => $stats['day'],
            'revenue' => (float) $stats['revenue'],
            'commission' => (float) $stats['commission'],
        ], $dailyData);
    }

    /**
     * Get weekly revenue data for course (7 days)
     */
    private function getCourseWeeklyRevenueData($courseId, $year, $week)
    {
        $weeklyData = $this->earningsService->getDailyDataForWeek($year, $week, null, $courseId);

        return array_map(static fn($stats) => [
            'name' => $stats['day_name'],
            'revenue' => (float) $stats['revenue'],
            'commission' => (float) $stats['commission'],
        ], $weeklyData);
    }

    /**
     * Get course earnings chart data with yearly, monthly, weekly options
     */
    private function getCourseEarningsChartData($courseId, $instructorId)
    {
        $currentYear = now()->year;

        return [
            'yearly' => $this->getCourseYearlyEarningsData($courseId, $currentYear),
            'monthly' => $this->getCourseMonthlyEarningsData($courseId, $currentYear, now()->month),
            'weekly' => $this->getCourseWeeklyEarningsData($courseId, $currentYear, now()->weekOfYear),
        ];
    }

    /**
     * Get yearly earnings data for course (12 months)
     */
    private function getCourseYearlyEarningsData($courseId, $year)
    {
        $monthlyData = $this->earningsService->getMonthlyData($year, null, $courseId);

        return array_map(static fn($stats) => [
            'name' => $stats['month'],
            'earning' => (float) $stats['earnings'],
        ], $monthlyData);
    }

    /**
     * Get monthly earnings data for course (30 days)
     */
    private function getCourseMonthlyEarningsData($courseId, $year, $month)
    {
        $dailyData = $this->earningsService->getDailyDataForMonth($year, $month, null, $courseId);

        return array_map(static fn($stats) => [
            'name' => $stats['day'],
            'earning' => (float) $stats['earnings'],
        ], $dailyData);
    }

    /**
     * Get weekly earnings data for course (7 days)
     */
    private function getCourseWeeklyEarningsData($courseId, $year, $week)
    {
        $weeklyData = $this->earningsService->getDailyDataForWeek($year, $week, null, $courseId);

        return array_map(static fn($stats) => [
            'name' => $stats['day_name'],
            'earning' => (float) $stats['earnings'],
        ], $weeklyData);
    }

    /**
     * Get course completion data
     */
    private function getCourseCompletionData($courseId)
    {
        $completions = UserCourseTrack::where('course_id', $courseId)
            ->where('status', 'completed')
            ->selectRaw('DATE(updated_at) as completion_date, COUNT(*) as count')
            ->where('updated_at', '>=', now()->subDays(30))
            ->groupBy('completion_date')
            ->orderBy('completion_date')
            ->get();

        $data = [];
        for ($i = 29; $i >= 0; $i--) {
            $date = now()->subDays($i)->format('Y-m-d');
            $completion = $completions->where('completion_date', $date)->first();
            $data[] = [
                'date' => $date,
                'completions' => $completion ? $completion->count : 0,
                'formatted_date' => now()->subDays($i)->format('M d'),
            ];
        }

        return $data;
    }

    /**
     * Get course rating data
     */
    private function getCourseRatingData($courseId)
    {
        $ratings = \App\Models\Rating::where('rateable_id', $courseId)
            ->where('rateable_type', \App\Models\Course\Course::class)
            ->selectRaw('rating, COUNT(*) as count')
            ->groupBy('rating')
            ->orderBy('rating')
            ->get();

        $ratingDistribution = [];
        for ($i = 1; $i <= 5; $i++) {
            $rating = $ratings->where('rating', $i)->first();
            $ratingDistribution[] = [
                'rating' => $i,
                'count' => $rating ? $rating->count : 0,
                'percentage' => 0, // Will be calculated below
            ];
        }

        $totalRatings = $ratings->sum('count');
        foreach ($ratingDistribution as &$rating) {
            $rating['percentage'] = $totalRatings > 0 ? round(($rating['count'] / $totalRatings) * 100, 1) : 0;
        }

        return [
            'distribution' => $ratingDistribution,
            'total_ratings' => $totalRatings,
            'average_rating' => $totalRatings > 0 ? round($ratings->avg('rating'), 2) : 0,
        ];
    }

    /**
     * Get recent activity
     */
    private function getRecentActivity($courseId)
    {
        $activities = collect();

        // Recent enrollments
        $recentEnrollments = UserCourseTrack::with('user')
            ->where('course_id', $courseId)
            ->orderBy('created_at', 'desc')
            ->limit(5)
            ->get()
            ->map(static fn($enrollment) => [
                'type' => 'enrollment',
                'message' => $enrollment->user->name . ' enrolled in the course',
                'date' => $enrollment->created_at,
                'formatted_date' => $enrollment->created_at->diffForHumans(),
            ]);

        // Recent completions
        $recentCompletions = UserCourseTrack::with('user')
            ->where('course_id', $courseId)
            ->where('status', 'completed')
            ->orderBy('updated_at', 'desc')
            ->limit(5)
            ->get()
            ->map(static fn($completion) => [
                'type' => 'completion',
                'message' => $completion->user->name . ' completed the course',
                'date' => $completion->updated_at,
                'formatted_date' => $completion->updated_at->diffForHumans(),
            ]);

        // Recent ratings
        $recentRatings = \App\Models\Rating::with('user')
            ->where('rateable_id', $courseId)
            ->where('rateable_type', \App\Models\Course\Course::class)
            ->orderBy('created_at', 'desc')
            ->limit(5)
            ->get()
            ->map(static fn($rating) => [
                'type' => 'rating',
                'message' => $rating->user->name . ' rated the course ' . $rating->rating . ' stars',
                'date' => $rating->created_at,
                'formatted_date' => $rating->created_at->diffForHumans(),
            ]);

        $activities = $activities->merge($recentEnrollments)->merge($recentCompletions)->merge($recentRatings);

        return $activities->sortByDesc('date')->take(10)->values();
    }
}
