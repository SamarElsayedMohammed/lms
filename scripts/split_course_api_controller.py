from pathlib import Path

src_path = Path(r"D:\Dev\skillso-main\backend-skillso\app\Http\Controllers\API\CourseApiController.php")
out_dir = Path(r"D:\Dev\skillso-main\backend-skillso\app\Http\Controllers\API\Concerns")
out_dir.mkdir(parents=True, exist_ok=True)

lines = src_path.read_text(encoding="utf-8").splitlines(True)

header = []
for line in lines:
    if line.startswith("class "):
        break
    header.append(line)

uses = "".join(line for line in header if line.startswith("use ") or line.startswith("namespace ") or line.startswith("<?php") or line.strip() == "")

# 1-indexed inclusive ranges of method bodies to extract (constructor stays in main class)
chunks = [
    ("ServesCourseCatalogList", 77, 843),
    ("ServesCourseCatalogDetail", 844, 2863),
    ("ServesInstructorCourseOps", 2864, 4548),
    ("ServesInstructorAnalytics", 4549, 6612),
    ("ServesCourseLearning", 6613, 9347),
    ("ServesCourseReviews", 9348, 11031),
    ("ServesCourseCertificates", 11032, 11523),
]

trait_uses = []
for name, start, end in chunks:
    body = "".join(lines[start - 1 : end])
    trait = (
        "<?php\n\n"
        "namespace App\\Http\\Controllers\\API\\Concerns;\n\n"
        + "".join(line for line in header if line.startswith("use "))
        + "\ntrait "
        + name
        + "\n{\n"
        + body
        + "}\n"
    )
    (out_dir / f"{name}.php").write_text(trait, encoding="utf-8")
    trait_uses.append(name)
    print(name, "lines", end - start + 1)

class_header = []
for i, line in enumerate(lines, 1):
    class_header.append(line)
    if i >= 76:
        break

# rewrite class to use traits and keep constructor only
new_class = (
    "<?php\n\n"
    "namespace App\\Http\\Controllers\\API;\n\n"
    "use App\\Http\\Controllers\\Controller;\n"
    "use App\\Http\\Controllers\\API\\Concerns\\ServesCourseCatalogList;\n"
    "use App\\Http\\Controllers\\API\\Concerns\\ServesCourseCatalogDetail;\n"
    "use App\\Http\\Controllers\\API\\Concerns\\ServesInstructorCourseOps;\n"
    "use App\\Http\\Controllers\\API\\Concerns\\ServesInstructorAnalytics;\n"
    "use App\\Http\\Controllers\\API\\Concerns\\ServesCourseLearning;\n"
    "use App\\Http\\Controllers\\API\\Concerns\\ServesCourseReviews;\n"
    "use App\\Http\\Controllers\\API\\Concerns\\ServesCourseCertificates;\n"
    "use App\\Services\\CourseProgressService;\n"
    "use App\\Services\\EarningsService;\n"
    "use App\\Services\\PricingCalculationService;\n"
    "use Illuminate\\Http\\Request;\n\n"
    "class CourseApiController extends Controller\n"
    "{\n"
    "    use ServesCourseCatalogList;\n"
    "    use ServesCourseCatalogDetail;\n"
    "    use ServesInstructorCourseOps;\n"
    "    use ServesInstructorAnalytics;\n"
    "    use ServesCourseLearning;\n"
    "    use ServesCourseReviews;\n"
    "    use ServesCourseCertificates;\n\n"
    "    private readonly string $uploadFolder;\n\n"
    "    private readonly string $videoUploadFolder;\n\n"
    "    private readonly string $metaImageUploadFolder;\n\n"
    "    public function __construct(\n"
    "        private readonly PricingCalculationService $pricingService,\n"
    "        private readonly EarningsService $earningsService,\n"
    "        private readonly CourseProgressService $progressService,\n"
    "    ) {\n"
    "        $this->uploadFolder = \"courses/thumbnail\";\n"
    "        $this->videoUploadFolder = \"courses/intro_video\";\n"
    "        $this->metaImageUploadFolder = \"courses/meta_image\";\n"
    "    }\n"
    "}\n"
)
src_path.write_text(new_class, encoding="utf-8")
print("rewrote CourseApiController")
