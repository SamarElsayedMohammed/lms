<?php

$collection = [
    "info" => [
        "name" => "LMS Reports API",
        "description" => "Collection containing all report endpoints in the LMS system with their filters",
        "schema" => "https://schema.getpostman.com/json/collection/v2.1.0/collection.json"
    ],
    "variable" => [
        ["key" => "base_url", "value" => "http://localhost:8000", "type" => "string"],
        ["key" => "token", "value" => "YOUR_ADMIN_TOKEN_HERE", "type" => "string"]
    ],
    "item" => [
        [
            "name" => "Admin Reports",
            "item" => [
                createRequest("Get Report Filters", "GET", "api/reports/filters"),
                createRequest("Sales Report", "GET", "api/reports/sales", [
                    "date_from" => "2023-01-01", "date_to" => "2023-12-31", 
                    "course_id" => "1", "instructor_id" => "1", 
                    "status" => "completed", "payment_method" => "stripe", 
                    "category_id" => "1", "report_type" => "detailed", 
                    "group_by" => "month", "per_page" => "15"
                ]),
                createRequest("Revenue Report", "GET", "api/reports/revenue", [
                    "date_from" => "2023-01-01", "date_to" => "2023-12-31", 
                    "course_id" => "1", "category_id" => "1", 
                    "payment_method" => "stripe", "report_type" => "summary", 
                    "group_by" => "month", "per_page" => "15"
                ]),
                createRequest("Credit Cards Revenue", "GET", "api/reports/credit-cards-revenue", [
                    "date_from" => "2023-01-01", "date_to" => "2023-12-31", 
                    "payment_method" => "stripe", "report_type" => "summary"
                ]),
                createRequest("Commission Report", "GET", "api/reports/commission", [
                    "date_from" => "2023-01-01", "date_to" => "2023-12-31", 
                    "course_id" => "1", "status" => "paid", 
                    "report_type" => "summary", "group_by" => "month", "per_page" => "15"
                ]),
                createRequest("Course Report", "GET", "api/reports/course", [
                    "date_from" => "2023-01-01", "date_to" => "2023-12-31", 
                    "course_id" => "1", "instructor_id" => "1", 
                    "category_id" => "1", "status" => "active", 
                    "approval_status" => "approved", "course_type" => "paid", 
                    "level" => "beginner", "report_type" => "summary", "per_page" => "15"
                ]),
                createRequest("Instructor Report", "GET", "api/reports/instructor", [
                    "date_from" => "2023-01-01", "date_to" => "2023-12-31", 
                    "instructor_id" => "1", "instructor_type" => "individual", 
                    "status" => "approved", "report_type" => "summary", "per_page" => "15"
                ]),
                createRequest("Enrollment Report", "GET", "api/reports/enrollment", [
                    "date_from" => "2023-01-01", "date_to" => "2023-12-31", 
                    "course_id" => "1", "instructor_id" => "1", 
                    "category_id" => "1", "status" => "completed", 
                    "report_type" => "summary", "group_by" => "month", "per_page" => "15"
                ]),
                createRequest("Students List", "GET", "api/reports/students", [
                    "search" => "", "per_page" => "15"
                ]),
                createRequest("Student Completion Stats", "GET", "api/reports/students/completion-stats"),
                createRequest("Specific Student Report", "GET", "api/reports/students/1"),
                createRequest("Admin Dashboard Data", "GET", "api/dashboard-data"),
                createRequest("Admin Dashboard Charts", "GET", "api/dashboard-charts"),
                createRequest("Finance Dashboard", "GET", "api/dashboard"),
                createRequest("Finance Reports List", "GET", "api/reports"),
                createRequest("Subscription Plan Report", "GET", "api/admin/subscription-plans/plan-report"),
                createRequest("Export Webinar Registrants", "GET", "api/webinars/1/registrants/export"),
            ]
        ],
        [
            "name" => "Instructor Reports",
            "item" => [
                createRequest("Instructor Dashboard", "GET", "api/get-instructor-dashboard"),
                createRequest("Quiz Reports List", "GET", "api/get-quiz-reports"),
                createRequest("Quiz Report Details", "GET", "api/get-quiz-report-details", [
                    "quiz_id" => "1"
                ])
            ]
        ],
        [
            "name" => "Student / User Reports",
            "item" => [
                createRequest("User Dashboard", "GET", "api/user/dashboard"),
                createRequest("Learning Stats", "GET", "api/user/learning-stats"),
                createRequest("Financial Transactions", "GET", "api/user/financial-transactions"),
                createRequest("Certificates List", "GET", "api/user/certificates"),
                createRequest("Comprehensive User Report", "GET", "api/reports/comprehensive")
            ]
        ]
    ]
];

function createRequest($name, $method, $path, $queryParams = []) {
    $queryArray = [];
    foreach($queryParams as $k => $v) {
        $queryArray[] = [
            "key" => $k,
            "value" => $v,
            "disabled" => true // Disable by default so they don't block the request if not needed
        ];
    }
    
    $pathArray = explode("/", $path);

    return [
        "name" => $name,
        "request" => [
            "method" => $method,
            "header" => [
                ["key" => "Accept", "value" => "application/json", "type" => "text"],
                ["key" => "Authorization", "value" => "Bearer {{token}}", "type" => "text"]
            ],
            "url" => [
                "raw" => "{{base_url}}/" . $path . (!empty($queryArray) ? "?" . http_build_query($queryParams) : ""),
                "host" => ["{{base_url}}"],
                "path" => $pathArray,
                "query" => $queryArray
            ]
        ]
    ];
}

file_put_contents('LMS_Reports_Postman_Collection.json', json_encode($collection, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
echo "Done";

