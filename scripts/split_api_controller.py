from pathlib import Path

src_path = Path(r"D:\Dev\skillso-main\backend-skillso\app\Http\Controllers\ApiController.php")
out_dir = Path(r"D:\Dev\skillso-main\backend-skillso\app\Http\Controllers\Concerns")
out_dir.mkdir(parents=True, exist_ok=True)

lines = src_path.read_text(encoding="utf-8").splitlines(True)
print("total", len(lines))

header = []
for line in lines:
    if line.startswith("class "):
        break
    header.append(line)

use_lines = "".join(line for line in header if line.startswith("use "))

chunks = [
    ("ServesApiAuth", 52, 1110),
    ("ServesApiAccount", 1111, 3386),
    ("ServesApiPublicContent", 3387, 4217),
    ("ServesApiSessions", 4218, len(lines) - 1),  # exclude final class brace
]

for name, start, end in chunks:
    body = "".join(lines[start - 1 : end])
    trait = (
        "<?php\n\n"
        "namespace App\\Http\\Controllers\\Concerns;\n\n"
        + use_lines
        + "\ntrait "
        + name
        + "\n{\n"
        + body
        + "}\n"
    )
    (out_dir / f"{name}.php").write_text(trait, encoding="utf-8")
    print(name, "lines", end - start + 1)

new_class = (
    "<?php\n\n"
    "namespace App\\Http\\Controllers;\n\n"
    "use App\\Http\\Controllers\\Concerns\\ServesApiAccount;\n"
    "use App\\Http\\Controllers\\Concerns\\ServesApiAuth;\n"
    "use App\\Http\\Controllers\\Concerns\\ServesApiPublicContent;\n"
    "use App\\Http\\Controllers\\Concerns\\ServesApiSessions;\n"
    "use Illuminate\\Http\\Request;\n\n"
    "class ApiController extends Controller\n"
    "{\n"
    "    use ServesApiAuth;\n"
    "    use ServesApiAccount;\n"
    "    use ServesApiPublicContent;\n"
    "    use ServesApiSessions;\n\n"
    "    public function __construct()\n"
    "    {\n"
    "        // Public auth endpoints must stay public even when the client still\n"
    "        // has a leftover Authorization header or ?token= query.\n"
    "        // Route groups already apply auth:sanctum where it is required.\n"
    "    }\n"
    "}\n"
)
src_path.write_text(new_class, encoding="utf-8")
print("rewrote ApiController")
