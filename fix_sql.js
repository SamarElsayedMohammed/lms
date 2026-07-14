const fs = require('fs');
let c = fs.readFileSync('app/Http/Controllers/API/HomeApiController.php', 'utf8');

const targetStr1 = `AND courses.deleted_at IS NULL`;
const targetStr2 = `)) as active_course_count')`;

const startIdx = c.indexOf(targetStr1);
const endIdx = c.indexOf(targetStr2, startIdx);

if (startIdx !== -1 && endIdx !== -1) {
    const before = c.substring(0, startIdx + targetStr1.length);
    const after = c.substring(endIdx + 1); // skip one parenthesis `)`
    c = before + after;
    fs.writeFileSync('app/Http/Controllers/API/HomeApiController.php', c, 'utf8');
    console.log("SQL fixed!");
} else {
    console.log("Could not find SQL block");
}
