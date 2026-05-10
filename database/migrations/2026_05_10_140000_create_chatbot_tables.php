<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        // Chatbot FAQ Buttons — أسئلة جاهزة تظهر كأزرار
        Schema::create('chatbot_faqs', function (Blueprint $table) {
            $table->id();
            $table->string('question', 255);       // نص الزر
            $table->text('answer');                  // الإجابة المحددة
            $table->string('category', 100)->nullable()->default('general'); // تصنيف
            $table->boolean('is_active')->default(true);
            $table->integer('sort_order')->default(0);
            $table->timestamps();
            $table->softDeletes();

            $table->index(['is_active', 'sort_order']);
        });

        // Knowledge Base — ملفات/نصوص المعرفة للـ AI
        Schema::create('chatbot_knowledge_bases', function (Blueprint $table) {
            $table->id();
            $table->string('title', 255);            // عنوان وصفي
            $table->longText('content');              // المحتوى النصي (اللي الـ AI بيقرأه)
            $table->string('file_path', 500)->nullable(); // مسار الملف الأصلي لو مرفوع
            $table->string('file_type', 50)->nullable();  // نوع الملف (txt, md, csv, etc.)
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            $table->index('is_active');
        });

        // إعدادات الشات بوت — في جدول settings الموجود
        $settings = [
            ['name' => 'chatbot_enabled', 'value' => '1', 'type' => 'boolean'],
            ['name' => 'chatbot_name', 'value' => 'سكيلزوا', 'type' => 'text'],
            ['name' => 'chatbot_welcome_message', 'value' => 'أنا سكيلزوا، مساعدك الذكي في Skillso. اسألني عن الكورسات، الخطط، أو أفضل مسار تتعلمه.', 'type' => 'text'],
            ['name' => 'chatbot_system_prompt', 'value' => 'أنت مساعد ذكي لمنصة Skillso التعليمية. أجب بالعربية بأسلوب ودود ومختصر. أجب فقط من قاعدة المعرفة المتاحة. لو السؤال خارج نطاقك، اعتذر بلطف واقترح التواصل مع الدعم.', 'type' => 'text'],
            ['name' => 'chatbot_max_tokens', 'value' => '500', 'type' => 'text'],
            // مكان الشات بوت على الصفحة: bottom-right, bottom-left, top-right, top-left
            ['name' => 'chatbot_position', 'value' => 'bottom-right', 'type' => 'text'],
            // أيقونة الشات بوت — الأدمن يقدر يرفع صورة مخصصة
            ['name' => 'chatbot_icon', 'value' => null, 'type' => 'file'],
        ];

        foreach ($settings as $setting) {
            DB::table('settings')->updateOrInsert(
                ['name' => $setting['name']],
                $setting
            );
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('chatbot_knowledge_bases');
        Schema::dropIfExists('chatbot_faqs');

        DB::table('settings')->whereIn('name', [
            'chatbot_enabled',
            'chatbot_name',
            'chatbot_welcome_message',
            'chatbot_system_prompt',
            'chatbot_max_tokens',
            'chatbot_position',
            'chatbot_icon',
        ])->delete();
    }
};
