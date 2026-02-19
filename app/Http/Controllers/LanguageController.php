<?php

namespace App\Http\Controllers;

use App\Models\Language;
use App\Rules\ValidJsonFile;
use App\Services\BootstrapTableService;
use App\Services\CachingService;
use App\Services\FileService;
use App\Services\ResponseService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Session;
use Illuminate\Support\Facades\Validator;
use Throwable;

class LanguageController extends Controller
{
    private readonly string $uploadFolder;

    public function __construct()
    {
        $this->uploadFolder = 'language';
    }

    public function index()
    {
        ResponseService::noPermissionThenRedirect('settings-language-list');

        return view('settings.language', ['type_menu' => 'Language']);
    }

    public function create()
    {
        return redirect()->route('language.index');
    }

    public function store(Request $request)
    {
        ResponseService::noPermissionThenSendJson('settings-language-create');
        $validator = Validator::make($request->all(), [
            'name' => 'required',
            'name_in_english' => 'required|regex:/^[\pL\s]+$/u',
            'code' => 'required|unique:languages,code',
            'rtl' => 'nullable',
            'image' => 'required|mimes:jpeg,png,jpg,svg|max:7168',
            'country_code' => 'nullable',
            'is_default' => 'nullable|boolean',
            'app_file' => ['nullable', 'file', 'max:5120', new ValidJsonFile()],
            'panel_file' => ['nullable', 'file', 'max:5120', new ValidJsonFile()],
            'web_file' => ['nullable', 'file', 'max:5120', new ValidJsonFile()],
        ]);
        if ($validator->fails()) {
            ResponseService::validationError($validator->errors()->first());
        }
        try {
            $data = $request->all();
            $data['rtl'] = $request->rtl == '1' ? 1 : 0;
            $data['panel_file'] = $data['app_file'] = $data['web_file'] = 'en.json';
            if ($request->hasFile('panel_file')) {
                $data['panel_file'] = FileService::uploadLanguageFile($request->file('panel_file'), $request->code);
            }

            if ($request->hasFile('app_file')) {
                $data['app_file'] = FileService::uploadLanguageFile(
                    $request->file('app_file'),
                    $request->code . '_app',
                );
            }

            if ($request->hasFile('web_file')) {
                $data['web_file'] = FileService::uploadLanguageFile(
                    $request->file('web_file'),
                    $request->code . '_web',
                );
            }

            if ($request->hasFile('image')) {
                $data['image'] = FileService::upload($request->file('image'), $this->uploadFolder);
            }

            Language::create($data);
            CachingService::removeCache(config('constants.CACHE.LANGUAGE'));
            CachingService::removeCache(config('constants.CACHE.DEFAULT_LANGUAGE'));
            ResponseService::successResponse('Language Successfully Added');
        } catch (Throwable $th) {
            ResponseService::logErrorRedirect($th, 'Language Controller -> Store');
            ResponseService::errorResponse('Something Went Wrong');
        }
    }

    public function show(Request $request)
    {
        ResponseService::noPermissionThenSendJson('settings-language-list');
        $offset = $request->offset ?? 0;
        $limit = $request->limit ?? 10;
        $sort = $request->sort ?? 'id';
        $order = $request->order ?? 'DESC';
        $search = $request->search ?? '';
        $showDeleted = $request->show_deleted ?? 0;

        $sql = Language::query();

        // Handle search with proper grouping
        if (!empty($search)) {
            $sql->where(static function ($query) use ($search): void {
                $query
                    ->where('id', 'LIKE', "%{$search}%")
                    ->orWhere('code', 'LIKE', "%{$search}%")
                    ->orWhere('name', 'LIKE', "%{$search}%")
                    ->orWhere('name_in_english', 'LIKE', "%{$search}%");
            });
        }

        // Note: Languages table doesn't have deleted_at column, so no soft delete handling

        $total = $sql->count();

        $sql->orderBy($sort, $order)->skip($offset)->take($limit);
        $result = $sql->get();
        $bulkData = [];
        $bulkData['total'] = $total;
        $rows = [];
        foreach ($result as $row) {
            $tempRow = $row->toArray();
            $tempRow['rtl_text'] = $row->rtl == 1 ? 'Yes' : 'No';
            $operate = '';
            // Show edit button for all languages (including default language)
            $operate .= BootstrapTableService::button(
                'fa fa-edit',
                route('language.edit', $row->id),
                ['btn-primary'],
                ['title' => 'Edit'],
            );
            // Only show delete button if language is not default
            if (!$row->is_default) {
                $operate .= BootstrapTableService::deleteButton(
                    route('language.destroy', $row->id),
                    null,
                    null,
                    null,
                    ' delete-language',
                );
            }
            $dropdownItems = [
                [
                    'icon' => '',
                    'url' => route('languageedit', [$row->id, 'type' => 'panel']),
                    'text' => 'Edit Panel Json',
                ],
                [
                    'icon' => '',
                    'url' => route('languageedit', [$row->id, 'type' => 'web']),
                    'text' => 'Edit Web Json',
                ],
                [
                    'icon' => '',
                    'url' => route('languageedit', [$row->id, 'type' => 'app']),
                    'text' => 'Edit App Json',
                ],
            ];

            $operate .= BootstrapTableService::dropdown('fas fa-ellipsis-v', $dropdownItems);

            $tempRow['operate'] = $operate;
            $rows[] = $tempRow;
        }

        $bulkData['rows'] = $rows;

        return response()->json($bulkData);
    }

    public function edit($id)
    {
        ResponseService::noPermissionThenRedirect('settings-language-edit');
        $language_data = Language::findOrFail($id);
        $languages = Language::get();

        return view('settings.languageedit', compact('language_data', 'languages'), ['type_menu' => 'Language']);
    }

    public function update(Request $request, $id)
    {
        ResponseService::noPermissionThenSendJson('settings-language-edit');
        $validator = Validator::make($request->all(), [
            'name' => 'required',
            'name_in_english' => 'required|regex:/^[\pL\s]+$/u',
            'code' => 'required|unique:languages,code,' . $id,
            'rtl' => 'nullable|boolean',
            'app_file' => ['nullable', 'file', 'max:5120', new ValidJsonFile()],
            'panel_file' => ['nullable', 'file', 'max:5120', new ValidJsonFile()],
            'web_file' => ['nullable', 'file', 'max:5120', new ValidJsonFile()],
            'image' => 'nullable|mimes:jpeg,png,jpg,svg|max:7168',
            'country_code' => 'nullable',
            'is_default' => 'nullable|boolean',
        ]);
        if ($validator->fails()) {
            return redirect()->back()->with('error', $validator->errors()->first());
        }
        try {
            $language = Language::findOrFail($id);
            $data = $request->all();

            // Handle RTL checkbox - value will be "1" if checked, "0" if not checked
            $data['rtl'] = $request->rtl == '1' ? 1 : 0;
            // Handle is_default checkbox
            $data['is_default'] = $request->has('is_default') ? ($request->is_default == 1 ? 1 : 0) : 0;

            if ($request->hasFile('panel_file')) {
                $data['panel_file'] = FileService::uploadLanguageFile($request->file('panel_file'), $language->code);
            }
            if ($request->hasFile('app_file')) {
                $data['app_file'] = FileService::uploadLanguageFile(
                    $request->file('app_file'),
                    $language->code . '_app',
                );
            }

            if ($request->hasFile('web_file')) {
                $data['web_file'] = FileService::uploadLanguageFile(
                    $request->file('web_file'),
                    $language->code . '_web',
                );
            }

            if ($request->hasFile('image')) {
                $data['image'] = FileService::replace(
                    $request->file('image'),
                    $this->uploadFolder,
                    $language->getRawOriginal('image'),
                );
            }
            $language->update($data);
            CachingService::removeCache(config('constants.CACHE.LANGUAGE'));
            CachingService::removeCache(config('constants.CACHE.DEFAULT_LANGUAGE'));

            return redirect()->route('language.index')->with('success', 'Language Updated successfully');
        } catch (Throwable $th) {
            ResponseService::logErrorRedirect($th, 'Language Controller --> update');

            return redirect()->back()->with('error', 'Something Went Wrong');
        }
    }

    public function destroy($id)
    {
        ResponseService::noPermissionThenSendJson('settings-language-delete');
        try {
            $language = Language::findOrFail($id);

            // Prevent deletion of default language
            if ($language->is_default) {
                return ResponseService::errorResponse('Default language cannot be deleted');
            }

            $language->delete();

            FileService::deleteLanguageFile($language->app_file);
            FileService::deleteLanguageFile($language->panel_file);
            FileService::deleteLanguageFile($language->web_file);
            FileService::delete($language->getRawOriginal('image'));
            CachingService::removeCache(config('constants.CACHE.LANGUAGE'));
            CachingService::removeCache(config('constants.CACHE.DEFAULT_LANGUAGE'));
            ResponseService::successResponse('Language Deleted successfully');
        } catch (Throwable $th) {
            ResponseService::logErrorRedirect($th, 'Language Controller --> Destroy');
            ResponseService::errorResponse('Something Went Wrong');
        }
    }

    public function setLanguage($languageCode)
    {
        $language = Language::where('code', $languageCode)->first();
        if (!empty($language)) {
            Session::put('locale', $language->code);
            Session::put('language', (object) $language->toArray());
            Session::put('rtl', $language->rtl); // Store RTL flag
            Session::save();
            app()->setLocale($language->code);
        }

        return redirect()->back();
    }

    public function editlanguage(Request $request, $id, $type)
    {
        $language = Language::findOrFail($id);
        $languageCode = $language->code ?? 'en';
        $type_menu = 'Language';

        if ($type == 'panel') {
            $fileName = $language->panel_file ?: "{$languageCode}.json";
            $defaultFile = base_path("resources/lang/{$languageCode}.json");
            // Fallback to English if language file doesn't exist
            if (!File::exists($defaultFile)) {
                $defaultFile = base_path('resources/lang/en_original.json');
            }
        } elseif ($type == 'web') {
            $fileName = $language->web_file ?: "{$languageCode}_web.json";
            $defaultFile = base_path("resources/lang/{$languageCode}_web.json");
            // Fallback to English if language file doesn't exist
            if (!File::exists($defaultFile)) {
                $defaultFile = base_path('resources/lang/en_web.json');
            }
        } elseif ($type == 'app') {
            $fileName = $language->app_file ?: "{$languageCode}_app.json";
            $defaultFile = base_path("resources/lang/{$languageCode}_app.json");
            // Fallback to English if language file doesn't exist
            if (!File::exists($defaultFile)) {
                $defaultFile = base_path('resources/lang/en_app.json');
            }
        } else {
            $fileName = 'en.json';
            $defaultFile = base_path('resources/lang/en_original.json');
        }

        $jsonFile = base_path("resources/lang/{$fileName}");

        if (!File::exists($jsonFile)) {
            if (File::exists($defaultFile)) {
                $defaultContent = File::get($defaultFile);
            } else {
                $defaultContent = json_encode([]);
            }

            File::put($jsonFile, $defaultContent);

            if ($type == 'panel') {
                $language->panel_file = $fileName;
            } elseif ($type == 'web') {
                $language->web_file = $fileName;
            } elseif ($type == 'app') {
                $language->app_file = $fileName;
            }
            $language->save();
        }

        $jsonContent = File::get($jsonFile);

        // Decode JSON with error handling
        $enContent = [];
        if (File::exists($defaultFile)) {
            $enJson = File::get($defaultFile);
            $decoded = json_decode($enJson, true);
            if (json_last_error() === JSON_ERROR_NONE && is_array($decoded)) {
                $enContent = $decoded;
            }
        }

        $targetContent = [];
        if (File::exists($jsonFile)) {
            $targetJson = File::get($jsonFile);
            $decoded = json_decode($targetJson, true);
            if (json_last_error() === JSON_ERROR_NONE && is_array($decoded)) {
                $targetContent = $decoded;
            }
        }

        // Merge target content with English content, prioritizing target language
        $mergedContent = [];

        // First, add all target language content
        foreach ($targetContent as $key => $targetValue) {
            // Check if value is string before trimming, skip arrays/objects
            if (is_string($targetValue) && !empty(trim($targetValue))) {
                $mergedContent[$key] = $targetValue;
            } elseif (!is_string($targetValue)) {
                // For arrays/objects, add them directly
                $mergedContent[$key] = $targetValue;
            }
        }

        // Then, add any missing English keys that don't exist in target language
        foreach ($enContent as $key => $englishValue) {
            if (!isset($mergedContent[$key])) {
                $mergedContent[$key] = $englishValue;
            } elseif (is_string($mergedContent[$key]) && empty(trim($mergedContent[$key]))) {
                $mergedContent[$key] = $englishValue;
            }
        }

        // Update the target file with merged content
        File::put($jsonFile, json_encode($mergedContent, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));

        // Prepare data for view: enLabels will contain English values for reference
        // and targetLabels will contain the actual translated values to show in form fields
        $enLabels = $enContent; // English labels for reference
        $targetLabels = $targetContent; // Target language translations

        return view('settings.languageeditjson', compact('enLabels', 'targetLabels', 'language', 'type', 'type_menu'));
    }

    public function updatelanguage(Request $request, $id, $type)
    {
        $language = Language::findOrFail($id);

        if ($type == 'panel') {
            $jsonFile = base_path('resources/lang/' . $language->panel_file);
        } elseif ($type == 'web') {
            $jsonFile = base_path('resources/lang/' . $language->web_file);
        } elseif ($type == 'app') {
            $jsonFile = base_path('resources/lang/' . $language->app_file);
        } else {
            $jsonFile = base_path('resources/lang/en_original.json');
        }

        $directory = dirname($jsonFile);
        if (!File::exists($directory)) {
            File::makeDirectory($directory, 0755, true);
        }

        if (!File::exists($jsonFile)) {
            $defaultContent = [];
            File::put($jsonFile, json_encode($defaultContent, JSON_PRETTY_PRINT));
        }
        $jsonContent = File::get($jsonFile);
        $enLabels = json_decode($jsonContent, true);

        $updatedLabels = $request->input('values');
        $keys = array_keys($enLabels);
        foreach ($keys as $index => $key) {
            if (!isset($updatedLabels[$index])) {
                continue;
            }

            $enLabels[$key] = $updatedLabels[$index];
        }
        File::put($jsonFile, json_encode($enLabels, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));

        ResponseService::successResponse('Json File updated successfully');
    }

    /**
     * Auto-translate missing language strings
     */
    public function autoTranslate(Request $request, $id, $type, $locale)
    {
        try {
            // Increase execution time limit for translation
            set_time_limit(300); // 5 minutes

            // Log job start
            Log::info('Auto-translate started', [
                'id' => $id,
                'type' => $type,
                'locale' => $locale,
            ]);

            // Use Artisan facade for synchronous execution
            $exitCode = Artisan::call('custom:translate-missing', [
                'type' => $type,
                'locale' => $locale,
            ]);

            if ($exitCode === 0) {
                $output = Artisan::output();
                Log::info('Auto-translate completed', [
                    'id' => $id,
                    'type' => $type,
                    'locale' => $locale,
                    'output' => trim($output),
                ]);

                return ResponseService::successResponse(trim($output));
            } else {
                $output = Artisan::output();
                Log::error('Auto-translate failed', [
                    'id' => $id,
                    'type' => $type,
                    'locale' => $locale,
                    'output' => trim($output),
                ]);

                return ResponseService::errorResponse('Translation failed: ' . trim($output));
            }
        } catch (\Throwable $th) {
            ResponseService::logErrorRedirect($th, 'Language Controller --> Auto Translate');

            return ResponseService::errorResponse('Failed to auto-translate: ' . $th->getMessage());
        }
    }

    /**
     * Save language file as {code}.json
     */
    public function saveAsCodeJson(Request $request, $id, $type)
    {
        try {
            $language = Language::findOrFail($id);
            $languageCode = $language->code ?? 'en';

            if ($type == 'panel') {
                $fileName = $language->panel_file ?: "{$languageCode}.json";
                $defaultFile = base_path('resources/lang/en_original.json');
            } elseif ($type == 'web') {
                $fileName = $language->web_file ?: "{$languageCode}_web.json";
                $defaultFile = base_path('resources/lang/en_web.json');
            } elseif ($type == 'app') {
                $fileName = $language->app_file ?: "{$languageCode}_app.json";
                $defaultFile = base_path('resources/lang/en_app.json');
            } else {
                $fileName = 'en.json';
                $defaultFile = base_path('resources/lang/en_original.json');
            }

            $jsonFile = base_path("resources/lang/{$fileName}");
            $targetContent = File::exists($jsonFile) ? json_decode(File::get($jsonFile), true) : [];

            // Create new filename as {code}.json
            $newFileName = "{$languageCode}.json";
            $newFilePath = base_path("resources/lang/{$newFileName}");

            // Save as {code}.json
            File::put($newFilePath, json_encode($targetContent, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));

            ResponseService::successResponse("Language file saved as {$newFileName} successfully.");
        } catch (\Throwable $th) {
            ResponseService::logErrorRedirect($th, 'Language Controller --> Save As Code Json');
            ResponseService::errorResponse('Failed to save file: ' . $th->getMessage());
        }
    }

    // translateStrings method removed - now using Artisan command

    /**
     * Download sample JSON files
     */
    public function downloadSampleFile($type)
    {
        try {
            $fileMap = [
                'panel' => [
                    'file' => base_path('resources/lang/en.json'),
                    'name' => 'en.json',
                    'fallback' => base_path('resources/lang/en_original.json'),
                ],
                'web' => [
                    'file' => base_path('resources/lang/en_web.json'),
                    'name' => 'en_web.json',
                    'fallback' => base_path('resources/lang/en.json'),
                ],
                'app' => [
                    'file' => base_path('resources/lang/en_app.json'),
                    'name' => 'en_app.json',
                    'fallback' => base_path('resources/lang/en.json'),
                ],
            ];

            if (!isset($fileMap[$type])) {
                return redirect()->back()->with('error', 'Invalid file type');
            }

            $fileInfo = $fileMap[$type];
            $filePath = $fileInfo['file'];

            // Use fallback if main file doesn't exist
            if (!File::exists($filePath) && $fileInfo['fallback'] && File::exists($fileInfo['fallback'])) {
                $filePath = $fileInfo['fallback'];
            }

            // If still doesn't exist, create a sample file
            if (!File::exists($filePath)) {
                $sampleContent = [
                    'welcome' => 'Welcome',
                    'hello' => 'Hello',
                    'goodbye' => 'Goodbye',
                    'save' => 'Save',
                    'cancel' => 'Cancel',
                    'delete' => 'Delete',
                    'edit' => 'Edit',
                    'create' => 'Create',
                    'update' => 'Update',
                    'name' => 'Name',
                    'email' => 'Email',
                    'password' => 'Password',
                    'submit' => 'Submit',
                    'search' => 'Search',
                    'filter' => 'Filter',
                    'actions' => 'Actions',
                ];

                $filePath = $fileInfo['file'];
                File::put($filePath, json_encode($sampleContent, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
            }

            return response()->download($filePath, $fileInfo['name'], [
                'Content-Type' => 'application/json',
            ]);
        } catch (Throwable $th) {
            ResponseService::logErrorRedirect($th, 'Language Controller --> Download Sample File');

            return redirect()->back()->with('error', 'Failed to download sample file');
        }
    }
}
