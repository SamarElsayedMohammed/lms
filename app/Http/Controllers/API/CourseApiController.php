<?php

namespace App\Http\Controllers\API;

use App\Http\Controllers\Controller;
use App\Http\Controllers\API\Concerns\ServesCourseCatalogList;
use App\Http\Controllers\API\Concerns\ServesCourseCatalogDetail;
use App\Http\Controllers\API\Concerns\ServesInstructorCourseOps;
use App\Http\Controllers\API\Concerns\ServesInstructorAnalytics;
use App\Http\Controllers\API\Concerns\ServesCourseLearning;
use App\Http\Controllers\API\Concerns\ServesCourseReviews;
use App\Http\Controllers\API\Concerns\ServesCourseCertificates;
use App\Services\CourseProgressService;
use App\Services\EarningsService;
use App\Services\PricingCalculationService;

class CourseApiController extends Controller
{
    use ServesCourseCatalogList;
    use ServesCourseCatalogDetail;
    use ServesInstructorCourseOps;
    use ServesInstructorAnalytics;
    use ServesCourseLearning;
    use ServesCourseReviews;
    use ServesCourseCertificates;

    private readonly string $uploadFolder;

    private readonly string $videoUploadFolder;

    private readonly string $metaImageUploadFolder;

    public function __construct(
        private readonly PricingCalculationService $pricingService,
        private readonly EarningsService $earningsService,
        private readonly CourseProgressService $progressService,
    ) {
        $this->uploadFolder = "courses/thumbnail";
        $this->videoUploadFolder = "courses/intro_video";
        $this->metaImageUploadFolder = "courses/meta_image";
    }
}
