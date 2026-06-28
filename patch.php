<?php
$file = 'app/Http/Controllers/API/SubscriptionApiController.php';
$content = file_get_contents($file);
$content = str_replace(
"        } catch (\Throwable \$e) {
            \Illuminate\Support\Facades\Log::error('Payment methods error: ' . \$e->getMessage() . ' in ' . \$e->getFile() . ':' . \$e->getLine());
            return ApiResponseService::errorResponse('Failed to retrieve payment methods: ' . \$e->getMessage() . ' at ' . basename(\$e->getFile()) . ':' . \$e->getLine());
        }",
"        } catch (\Illuminate\Http\Exceptions\HttpResponseException \$e) {
            throw \$e;
        } catch (\Throwable \$e) {
            return ApiResponseService::errorResponse('Failed to retrieve payment methods: ' . \$e->getMessage());
        }", $content);
file_put_contents($file, $content);
