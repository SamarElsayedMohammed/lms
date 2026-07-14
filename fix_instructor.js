const fs = require('fs');
let f = 'app/Http/Controllers/API/InstructorApiController.php';
let c = fs.readFileSync(f, 'utf8');
c = c.replace(/->where\('approval_status', 'approved'\);\s*\n\s*\}\)/g, "->where('approval_status', 'approved')");
fs.writeFileSync(f, c, 'utf8');
