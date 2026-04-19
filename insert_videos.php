<?php

use Illuminate\Support\Facades\DB;
use App\Models\Course\CourseChapter\CourseChapter;
use App\Models\Course\CourseChapter\Lecture\CourseChapterLecture;
use Illuminate\Support\Str;

$videos = [
[5, 5, 'مقدمه 2025', 'https://iframe.mediadelivery.net/embed/423625/67a718d8-d060-4e1a-bae4-2301d51b1f46', '67a718d8-d060-4e1a-bae4-2301d51b1f46', '2026-03-23 04:39:38'],
[6, 5, 'محتوى الكورس 2025', 'https://iframe.mediadelivery.net/embed/423625/a04dbd1d-805e-45af-b06e-ff8b0c1fa773', 'a04dbd1d-805e-45af-b06e-ff8b0c1fa773', '2026-03-23 04:39:38'],
[7, 5, 'Tips  Tricks', 'https://iframe.mediadelivery.net/embed/423625/8560aa64-82cf-416c-aa7b-ce32097c8c26', '8560aa64-82cf-416c-aa7b-ce32097c8c26', '2026-03-23 04:39:38'],
[8, 5, 'Create page 2025', 'https://iframe.mediadelivery.net/embed/423625/1e98aba0-edfa-49bf-9b9a-b7372e330e27', '1e98aba0-edfa-49bf-9b9a-b7372e330e27', '2026-03-23 04:39:38'],
[9, 5, 'page settings.', 'https://iframe.mediadelivery.net/embed/423625/8d306fba-f755-4655-8c95-200b08122aaf', '8d306fba-f755-4655-8c95-200b08122aaf', '2026-03-23 04:39:38'],
[10, 5, 'messaging', 'https://iframe.mediadelivery.net/embed/423625/f50a2964-e4d5-4ed6-afa1-5246a205989e', 'f50a2964-e4d5-4ed6-afa1-5246a205989e', '2026-03-23 04:39:38'],
[11, 5, 'page settings 2.', 'https://iframe.mediadelivery.net/embed/423625/f6bbb0da-cb95-48f3-b91c-c6e8f5258efb', 'f6bbb0da-cb95-48f3-b91c-c6e8f5258efb', '2026-03-23 04:39:38'],
[12, 5, 'page settings 3', 'https://iframe.mediadelivery.net/embed/423625/250a7b1d-1121-463a-96c0-57c5adf1d748', '250a7b1d-1121-463a-96c0-57c5adf1d748', '2026-03-23 04:39:38'],
[13, 5, 'كيفيه ربط الجروب بصفحتك على الفيس بوك', 'https://iframe.mediadelivery.net/embed/423625/4f73ca52-5f13-487e-9275-a6ebdf20655f', '4f73ca52-5f13-487e-9275-a6ebdf20655f', '2026-03-23 04:39:38'],
[14, 5, 'الجزء 2 شات بوت1', 'https://iframe.mediadelivery.net/embed/423625/9f23303a-ae6c-44c9-a9ca-7c01260881b5', '9f23303a-ae6c-44c9-a9ca-7c01260881b5', '2026-03-23 04:39:38'],
[15, 5, 'الجزء 2 شات بوت2', 'https://iframe.mediadelivery.net/embed/423625/c0e75282-eba6-46c3-8b0a-a6e193db17c0', 'c0e75282-eba6-46c3-8b0a-a6e193db17c0', '2026-03-23 04:39:38'],
[16, 5, 'الجزء 2 شات بوت 3', 'https://iframe.mediadelivery.net/embed/423625/57674f24-6bb5-4721-97ed-cbaa21aa2ba8', '57674f24-6bb5-4721-97ed-cbaa21aa2ba8', '2026-03-23 04:39:38'],
[17, 5, 'الجزء 2 شات بوت 4', 'https://iframe.mediadelivery.net/embed/423625/da9c6769-ad1b-47ee-9012-9908b53c17be', 'da9c6769-ad1b-47ee-9012-9908b53c17be', '2026-03-23 04:39:38'],
[18, 5, 'الجزء 3 كيفيه تحليل المنافسين على الفيس بوك 1', 'https://iframe.mediadelivery.net/embed/423625/36edf16e-8139-4228-9f6e-af06b08aeeed', '36edf16e-8139-4228-9f6e-af06b08aeeed', '2026-03-23 04:39:38'],
[19, 5, 'الجزء 3 كيفيه تحليل المنافسين على الفيس بوك 2', 'https://iframe.mediadelivery.net/embed/423625/b851dadb-0a11-4eb6-9f64-9f15ac1f64a1', 'b851dadb-0a11-4eb6-9f64-9f15ac1f64a1', '2026-03-23 04:39:38'],
[20, 5, 'الجزء 3 كيفيه تحليل المنافسين على الفيس بوك 3', 'https://iframe.mediadelivery.net/embed/423625/99ac7258-da23-44ac-b3e4-45775a62e773', '99ac7258-da23-44ac-b3e4-45775a62e773', '2026-03-23 04:39:38'],
[21, 5, 'الجزء 4 ال6 اسرار فى تهيئه صفحتك لمحركات البحث', 'https://iframe.mediadelivery.net/embed/423625/25f82c66-1c46-4273-9e7f-092fcf8c283a', '25f82c66-1c46-4273-9e7f-092fcf8c283a', '2026-03-23 04:39:38'],
[22, 5, 'الجزء 4 creator studio', 'https://iframe.mediadelivery.net/embed/423625/41f0546c-2923-46e8-862b-dd2158797eb2', '41f0546c-2923-46e8-862b-dd2158797eb2', '2026-03-23 04:39:38'],
[23, 5, 'الجزء 4 Set up Facebook Business Manager', 'https://iframe.mediadelivery.net/embed/423625/1bf5eca3-b24e-42c1-b3d4-cee5c641e291', '1bf5eca3-b24e-42c1-b3d4-cee5c641e291', '2026-03-23 04:39:38'],
[24, 5, 'الجزء 4 The Power of Engagement', 'https://iframe.mediadelivery.net/embed/423625/926a7d0f-0cd2-42ad-9909-58247794280e', '926a7d0f-0cd2-42ad-9909-58247794280e', '2026-03-23 04:39:38'],
[25, 5, 'الجزء 4 Understand the Facebook algorithms', 'https://iframe.mediadelivery.net/embed/423625/5e8a960a-44fb-4367-ab3c-8e75d7145ab3', '5e8a960a-44fb-4367-ab3c-8e75d7145ab3', '2026-03-23 04:39:38'],
[26, 5, 'الجزء 5 mindset', 'https://iframe.mediadelivery.net/embed/423625/2c933cc4-5931-417e-97bd-c514cf756fa5', '2c933cc4-5931-417e-97bd-c514cf756fa5', '2026-03-23 04:39:38'],
[27, 5, 'الجزء 5 بدء العمل على حسابك الاعلانى', 'https://iframe.mediadelivery.net/embed/423625/79d89ab0-9f8b-45c8-b9b9-38b671d32862', '79d89ab0-9f8b-45c8-b9b9-38b671d32862', '2026-03-23 04:39:38'],
[28, 5, 'الجزء 5 اعدادت الحساب الاعلانى', 'https://iframe.mediadelivery.net/embed/423625/8d2894f7-8f98-40c5-9151-7bd13b6afdfa', '8d2894f7-8f98-40c5-9151-7bd13b6afdfa', '2026-03-23 04:39:38'],
[29, 5, 'الجزء 5 اعلان الوعى بالعلامه التجاريه.', 'https://iframe.mediadelivery.net/embed/423625/23a6cc91-4605-4ce7-9358-1676225ebecd', '23a6cc91-4605-4ce7-9358-1676225ebecd', '2026-03-23 04:39:38'],
[30, 5, 'الجزء 5 اعلان الزيارات.', 'https://iframe.mediadelivery.net/embed/423625/3a600fa3-4347-48d9-b739-b2531c1e3d16', '3a600fa3-4347-48d9-b739-b2531c1e3d16', '2026-03-23 04:39:38'],
[31, 5, 'الجزء 5 اعلان التفاعل', 'https://iframe.mediadelivery.net/embed/423625/519fad73-92c2-412a-8520-ea476ac95562', '519fad73-92c2-412a-8520-ea476ac95562', '2026-03-23 04:39:38'],
[32, 5, 'الجزء 5 اعلان الرسائل', 'https://iframe.mediadelivery.net/embed/423625/bfdf0ec1-adaf-42f1-8607-1bafaf6dc7fe', 'bfdf0ec1-adaf-42f1-8607-1bafaf6dc7fe', '2026-03-23 04:39:38'],
[33, 5, 'الجزء 5 التقارير', 'https://iframe.mediadelivery.net/embed/423625/5bf98d3a-1a32-49cf-95fd-6310b7a0ea38', '5bf98d3a-1a32-49cf-95fd-6310b7a0ea38', '2026-03-23 04:39:38'],
[34, 5, 'الجزء 5 اعلان تجميع بيانات العملاء المحتملين', 'https://iframe.mediadelivery.net/embed/423625/79cb33e4-3ed8-4edd-a0a3-f3f018513abb', '79cb33e4-3ed8-4edd-a0a3-f3f018513abb', '2026-03-23 04:39:38'],
[35, 5, 'الجزء 5 اعلان التطبيق', 'https://iframe.mediadelivery.net/embed/423625/14c2b173-229a-44c1-9fe3-2b49184fbe69', '14c2b173-229a-44c1-9fe3-2b49184fbe69', '2026-03-23 04:39:38'],
[36, 5, 'الجزء 5 الفوتره', 'https://iframe.mediadelivery.net/embed/423625/a436c019-391e-403d-bc28-0140effb6722', 'a436c019-391e-403d-bc28-0140effb6722', '2026-03-23 04:39:38'],
[37, 5, 'الجزء 5 طرق الدفع فى مصر بعد ايقاف بعض كروت البنكيه', 'https://iframe.mediadelivery.net/embed/423625/c97ba74b-4418-4696-94fd-3da706cff8cf', 'c97ba74b-4418-4696-94fd-3da706cff8cf', '2026-03-23 04:39:38'],
[38, 5, 'الجزء 5 أسرار لمعرفة استهدفات وحملات منافسينك', 'https://iframe.mediadelivery.net/embed/423625/775899a6-d45f-4ab7-b663-47a944dc5def', '775899a6-d45f-4ab7-b663-47a944dc5def', '2026-03-23 04:39:38'],
[39, 5, 'الجزء 5 chatgpt تحديد شخصية العميل من خلال', 'https://iframe.mediadelivery.net/embed/423625/a882e92b-f6c0-4147-9d29-bdaa9e6909b5', 'a882e92b-f6c0-4147-9d29-bdaa9e6909b5', '2026-03-23 04:39:38'],
[40, 5, 'الجزء 5 chatgpt كيفيه عمل خطه لأعلاناتك بناء على ميزانية محددة من خلال ال', 'https://iframe.mediadelivery.net/embed/423625/96d9f541-db4a-462b-839a-540e4357db19', '96d9f541-db4a-462b-839a-540e4357db19', '2026-03-23 04:39:38'],
[41, 6, 'خطة التسويق على السوشيال ميديا', 'https://iframe.mediadelivery.net/embed/423625/6ba26728-7205-4448-9b20-e8cdc9a048a9', '6ba26728-7205-4448-9b20-e8cdc9a048a9', '2026-03-23 04:39:38'],
[42, 7, 'مقدمه', 'https://iframe.mediadelivery.net/embed/423625/222fa0bc-1278-49e3-a7b5-787c6e572333', '222fa0bc-1278-49e3-a7b5-787c6e572333', '2026-03-23 04:39:38'],
[43, 7, 'نصائح هامة قبل بدء اعلاناتك', 'https://iframe.mediadelivery.net/embed/423625/a433bc8b-f0bb-4a4a-877b-b47f10b5d5c1', 'a433bc8b-f0bb-4a4a-877b-b47f10b5d5c1', '2026-03-23 04:39:38'],
[44, 7, 'سناب شات.', 'https://iframe.mediadelivery.net/embed/423625/afe70c6b-a270-4832-8951-ec5a1d16198e', 'afe70c6b-a270-4832-8951-ec5a1d16198e', '2026-03-23 04:39:38'],
[45, 7, 'نموذج لاعلان على سناب شات', 'https://iframe.mediadelivery.net/embed/423625/86ff3b44-74fd-4495-927d-60164a3d14b2', '86ff3b44-74fd-4495-927d-60164a3d14b2', '2026-03-23 04:39:38'],
[46, 7, 'جميع أسباب رفض الحملات الإعلانية-الجزء الاول', 'https://iframe.mediadelivery.net/embed/423625/37821ba1-dd8e-4977-8a85-f1bca0d16312', '37821ba1-dd8e-4977-8a85-f1bca0d16312', '2026-03-23 04:39:38'],
[47, 7, 'جميع أسباب رفض الحملات الإعلانية-الجزء الثانى', 'https://iframe.mediadelivery.net/embed/423625/1506261d-ae47-4fc0-ba13-0804f83078c2', '1506261d-ae47-4fc0-ba13-0804f83078c2', '2026-03-23 04:39:38'],
[48, 8, 'احترف إعلانات اليوتيوب', 'https://iframe.mediadelivery.net/embed/423625/234dfa67-dca3-4fd5-bd25-24c7e8fce352', '234dfa67-dca3-4fd5-bd25-24c7e8fce352', '2026-03-23 04:39:38'],
[49, 9, 'محاضره 1', 'https://iframe.mediadelivery.net/embed/423625/8dc52251-27a4-4a67-842a-420cc7a27425', '8dc52251-27a4-4a67-842a-420cc7a27425', '2026-03-23 04:39:38'],
[50, 9, 'محاضره 2', 'https://iframe.mediadelivery.net/embed/423625/64c7e21b-7959-447c-a6ae-d643b7c1710c', '64c7e21b-7959-447c-a6ae-d643b7c1710c', '2026-03-23 04:39:38'],
[51, 9, 'محاضره 3', 'https://iframe.mediadelivery.net/embed/423625/8b4a2f66-9f2d-4eb8-b613-916dddc1afd6', '8b4a2f66-9f2d-4eb8-b613-916dddc1afd6', '2026-03-23 04:39:38'],
[52, 9, 'محاضره 4', 'https://iframe.mediadelivery.net/embed/423625/2f52b40e-44a8-4d26-b1ac-8f9520c2b30b', '2f52b40e-44a8-4d26-b1ac-8f9520c2b30b', '2026-03-23 04:39:38'],
[53, 9, 'محاضره 5', 'https://iframe.mediadelivery.net/embed/423625/8a71e9a9-aa46-457d-b58b-d2f2817c14a1', '8a71e9a9-aa46-457d-b58b-d2f2817c14a1', '2026-03-23 04:39:38'],
[54, 9, 'محاضره 6', 'https://iframe.mediadelivery.net/embed/423625/fcd19fee-939d-421b-a938-b299ce0e0ffa', 'fcd19fee-939d-421b-a938-b299ce0e0ffa', '2026-03-23 04:39:38'],
[55, 9, 'محاضره 7', 'https://iframe.mediadelivery.net/embed/423625/d37dfd2b-31f8-4d82-8f3c-ae6509fe0ddd', 'd37dfd2b-31f8-4d82-8f3c-ae6509fe0ddd', '2026-03-23 04:39:38'],
[56, 9, 'محاضره 8', 'https://iframe.mediadelivery.net/embed/423625/b5cd2ea4-5a14-4d32-ac68-8a05d53ec48e', 'b5cd2ea4-5a14-4d32-ac68-8a05d53ec48e', '2026-03-23 04:39:38'],
[57, 9, 'محاضره 9', 'https://iframe.mediadelivery.net/embed/423625/694cd001-f1b1-4c71-8b64-ac04f4dce3c2', '694cd001-f1b1-4c71-8b64-ac04f4dce3c2', '2026-03-23 04:39:38'],
[58, 9, 'محاضره 10', 'https://iframe.mediadelivery.net/embed/423625/8c2c426b-2875-4bd8-973c-b442bf7399b7', '8c2c426b-2875-4bd8-973c-b442bf7399b7', '2026-03-23 04:39:38'],
[59, 9, 'محاضره 11', 'https://iframe.mediadelivery.net/embed/423625/3b018df2-0495-475e-999f-2b2b907c5339', '3b018df2-0495-475e-999f-2b2b907c5339', '2026-03-23 04:39:38'],
[60, 10, '(الجزء الأول SMC COURSE اساسيات )  مقدمه', 'https://iframe.mediadelivery.net/embed/423625/c9efd8b9-1fcf-488c-b1e4-99251b961845', 'c9efd8b9-1fcf-488c-b1e4-99251b961845', '2026-03-23 04:39:38'],
[61, 10, '(الجزء الأول SMC COURSE اساسيات )  المحاضره الأولى التعرف على الأسواق وانواعها', 'https://iframe.mediadelivery.net/embed/423625/4197f96e-c4e7-4a5d-938c-732784ae84fe', '4197f96e-c4e7-4a5d-938c-732784ae84fe', '2026-03-23 04:39:38'],
[62, 10, '(الجزء الأول SMC COURSE اساسيات )  المحاضره الثانية  مميزات سوق الفوركس', 'https://iframe.mediadelivery.net/embed/423625/1ff75997-1d0c-499b-8d46-1d4e21ba4f2f', '1ff75997-1d0c-499b-8d46-1d4e21ba4f2f', '2026-03-23 04:39:38'],
[63, 10, '(الجزء الأول SMC COURSE اساسيات )  المحاضره الثالثة كل ما يخص البروكر', 'https://iframe.mediadelivery.net/embed/423625/5de95b81-8ff3-4393-b6eb-a233fcefaf53', '5de95b81-8ff3-4393-b6eb-a233fcefaf53', '2026-03-23 04:39:38'],
[64, 10, '(الجزء الأول SMC COURSE اساسيات ) المحاضره الرابعة أزواج العملات والرافعة المالية', 'https://iframe.mediadelivery.net/embed/423625/c4230dd2-5c99-4443-88bf-f06f5c897e77', 'c4230dd2-5c99-4443-88bf-f06f5c897e77', '2026-03-23 04:39:38'],
[65, 10, '(الجزء الأول SMC COURSE اساسيات )  المحاضره الخامسة تكمله أساسيات', 'https://iframe.mediadelivery.net/embed/423625/4064ec39-3428-49bf-af13-d6006b45081e', '4064ec39-3428-49bf-af13-d6006b45081e', '2026-03-23 04:39:38'],
[66, 10, '(الجزء الأول SMC COURSE اساسيات ) المحاضره السادسة عملي', 'https://iframe.mediadelivery.net/embed/423625/9c972b2a-b8ce-461a-922c-3e19c90e6b82', '9c972b2a-b8ce-461a-922c-3e19c90e6b82', '2026-03-23 04:39:38'],
[67, 10, '(الجزء الأول SMC COURSE اساسيات ) تعريف منصة التحليل تريدينج فيو (Tradingview)', 'https://iframe.mediadelivery.net/embed/423625/b2503505-a62b-4601-a034-6e8906de8d1c', 'b2503505-a62b-4601-a034-6e8906de8d1c', '2026-03-23 04:39:38'],
[68, 10, 'الجزء الثاني التحليل الفني - المحاضره الأولى تحليل فني : القمم والقيعان', 'https://iframe.mediadelivery.net/embed/423625/24aa5f30-7b05-4bf5-9c34-3e686e2f0923', '24aa5f30-7b05-4bf5-9c34-3e686e2f0923', '2026-03-23 04:39:38'],
[69, 10, 'الجزء الثاني التحليل الفني - المحاضره الثانية: الدعوم والمقاومات', 'https://iframe.mediadelivery.net/embed/423625/514a90cb-e05c-41d8-b2d8-b8a66bbc8ec3', '514a90cb-e05c-41d8-b2d8-b8a66bbc8ec3', '2026-03-23 04:39:38'],
[70, 10, 'الجزء الثاني التحليل الفني - تطبيق عملي على الدعوم و والمقاومات', 'https://iframe.mediadelivery.net/embed/423625/5632625a-f9cc-4130-a319-7c88bf718bb0', '5632625a-f9cc-4130-a319-7c88bf718bb0', '2026-03-23 04:39:38'],
[71, 10, 'الجزء الثاني التحليل الفني - محاضره نماذج هندسة نماذج المثلثات', 'https://iframe.mediadelivery.net/embed/423625/e0b0c196-e4f2-442b-ba51-36e717c76c39', 'e0b0c196-e4f2-442b-ba51-36e717c76c39', '2026-03-23 04:39:38'],
[72, 10, 'الجزء الثاني التحليل الفني - محاضره الترندات و كسر الترندات', 'https://iframe.mediadelivery.net/embed/423625/cba6bd1d-aa79-4bf6-8fd6-efd6b86d40ad', 'cba6bd1d-aa79-4bf6-8fd6-efd6b86d40ad', '2026-03-23 04:39:38'],
[73, 10, 'الجزء الثاني التحليل الفني - محاضره اشكال هندسة قنوات السعرية و كسرها', 'https://iframe.mediadelivery.net/embed/423625/9858be43-8dba-4409-8b54-7816d380b0f3', '9858be43-8dba-4409-8b54-7816d380b0f3', '2026-03-23 04:39:38'],
[74, 10, 'الجزء الثالث SMC -  SMC MARKET STRUCTUR\n         PART 1 BOS', 'https://iframe.mediadelivery.net/embed/423625/d800de7d-80da-42ce-b1bc-89985be46b60', 'd800de7d-80da-42ce-b1bc-89985be46b60', '2026-03-23 04:39:38'],
[75, 10, 'الجزء الثالث SMC - MARKET STRUCTUR\n           SMC\n         PART 2  CHOCH', 'https://iframe.mediadelivery.net/embed/423625/994da653-6022-4d57-a694-5f84d2d9d4fa', '994da653-6022-4d57-a694-5f84d2d9d4fa', '2026-03-23 04:39:38'],
[76, 10, 'الجزء الثالث SMC -  SUPPLYSUPPLY & DEMAND\n\nORDER FLOW', 'https://iframe.mediadelivery.net/embed/423625/6b905eb9-099f-4b4a-b81c-afca6b2c169a', '6b905eb9-099f-4b4a-b81c-afca6b2c169a', '2026-03-23 04:39:38'],
[77, 10, 'الجزء الثالث SMC - SUPPLY & DEMAND\n\nORDER BLOCK OB', 'https://iframe.mediadelivery.net/embed/423625/6d09012e-0451-418f-bde4-5ff9e318f4ab', '6d09012e-0451-418f-bde4-5ff9e318f4ab', '2026-03-23 04:39:38'],
[78, 10, 'الجزء الثالث SMC -  SUPPLY & DEMAND\nBREACKER  BLOCK, BB', 'https://iframe.mediadelivery.net/embed/423625/52e35bcc-a647-4d79-8794-617d557fe8b5', '52e35bcc-a647-4d79-8794-617d557fe8b5', '2026-03-23 04:39:38'],
[79, 10, 'الجزء الثالث SMC -  SUPPLY & DEMAND\n\nRJ BLOCK RJB', 'https://iframe.mediadelivery.net/embed/423625/17a2661d-c0c9-486e-ac6a-9e9f7ef5d368', '17a2661d-c0c9-486e-ac6a-9e9f7ef5d368', '2026-03-23 04:39:38'],
[80, 10, 'الجزء الثالث SMC - SMC FVG', 'https://iframe.mediadelivery.net/embed/423625/9d3f0b4a-6910-4af6-b2c6-6448645ddd5e', '9d3f0b4a-6910-4af6-b2c6-6448645ddd5e', '2026-03-23 04:39:38'],
[81, 10, 'الجزء الثالث SMC - SMC MULTI TIME FRAM', 'https://iframe.mediadelivery.net/embed/423625/3d7fb576-4fd9-4291-98d9-98434a2d3f9f', '3d7fb576-4fd9-4291-98d9-98434a2d3f9f', '2026-03-23 04:39:38'],
[82, 10, 'الجزء الثالث SMC -  IDM / LIQUIDITY', 'https://iframe.mediadelivery.net/embed/423625/4f5ad081-040e-4bb2-9fa6-a5deac43ff7c', '4f5ad081-040e-4bb2-9fa6-a5deac43ff7c', '2026-03-23 04:39:38'],
[83, 10, 'الجزء الثالث SMC - CONFIRMATION OF ENTRY', 'https://iframe.mediadelivery.net/embed/423625/fd2bfa73-f168-4e7d-9e6e-9ca1dd205bfa', 'fd2bfa73-f168-4e7d-9e6e-9ca1dd205bfa', '2026-03-23 04:39:38'],
[84, 11, 'الجزء الأول - 1-ما هو التداول وما هى الاسواق الماليه', 'https://iframe.mediadelivery.net/embed/423625/4a1b6b2c-035c-4655-8087-199fd0172417', '4a1b6b2c-035c-4655-8087-199fd0172417', '2026-03-23 04:39:38'],
[85, 11, 'الجزء الأول - 2-ما هو سوق الفوركس', 'https://iframe.mediadelivery.net/embed/423625/86960f38-82ce-4951-9bd9-b80d90209727', '86960f38-82ce-4951-9bd9-b80d90209727', '2026-03-23 04:39:38'],
[86, 11, 'الجزء الأول - 3-ما هو  البروكر', 'https://iframe.mediadelivery.net/embed/423625/84d8dc49-26d4-49d3-ae49-0945074902af', '84d8dc49-26d4-49d3-ae49-0945074902af', '2026-03-23 04:39:38'],
[87, 11, 'الجزء الأول - 4-ما هى المنصه', 'https://iframe.mediadelivery.net/embed/423625/9ac5dec2-c0d4-4a32-95b7-f6f0f27745aa', '9ac5dec2-c0d4-4a32-95b7-f6f0f27745aa', '2026-03-23 04:39:38'],
[88, 11, 'الجزء الأول - 5-كل ما يخص إداره رأس المال', 'https://iframe.mediadelivery.net/embed/423625/a0979c43-dbd4-4462-8ee0-b14871d98494', 'a0979c43-dbd4-4462-8ee0-b14871d98494', '2026-03-23 04:39:38'],
[89, 11, 'الجزء الأول - 6-اشهر نوعين للتحليل', 'https://iframe.mediadelivery.net/embed/423625/bad1c6cd-276f-4917-967a-8bacd601bd40', 'bad1c6cd-276f-4917-967a-8bacd601bd40', '2026-03-23 04:39:38'],
[90, 11, 'الجزء الأول - 7-ابلكيشن Tradingview وأهم الادوات', 'https://iframe.mediadelivery.net/embed/423625/ea45d026-dbc6-4da3-aec6-74ff15208784', 'ea45d026-dbc6-4da3-aec6-74ff15208784', '2026-03-23 04:39:38'],
[91, 11, 'الجزء الأول - 8-ماهى الدعوم والمقاومات', 'https://iframe.mediadelivery.net/embed/423625/f6438ae3-3104-436b-ad19-8f832a468a22', 'f6438ae3-3104-436b-ad19-8f832a468a22', '2026-03-23 04:39:38'],
[92, 11, 'الجزء الأول - 9-ماهى التريندات وأنواعها', 'https://iframe.mediadelivery.net/embed/423625/9405ae63-5b85-4eee-a320-d20e2bba5298', '9405ae63-5b85-4eee-a320-d20e2bba5298', '2026-03-23 04:39:38'],
[93, 11, 'الجزء الأول - 10-ماهو الفيبوناتشي وأنواعه', 'https://iframe.mediadelivery.net/embed/423625/7419b80d-f52b-484d-8770-9911237a6359', '7419b80d-f52b-484d-8770-9911237a6359', '2026-03-23 04:39:38'],
[94, 11, 'الجزء الثاني - 1-مقدمة فى التحليل الموجي', 'https://iframe.mediadelivery.net/embed/423625/46ac7a48-e185-48bb-a1ca-5e144fea078d', '46ac7a48-e185-48bb-a1ca-5e144fea078d', '2026-03-23 04:39:38'],
[95, 11, 'الجزء الثاني - 2-ماهى قوانين وقواعد الموجه الدافعة', 'https://iframe.mediadelivery.net/embed/423625/749428f2-46bc-4842-949d-f84565644724', '749428f2-46bc-4842-949d-f84565644724', '2026-03-23 04:39:39'],
[96, 11, 'الجزء الثاني - 3-الطرق الاحترافيه للتنبؤ بالموجه الدافعة', 'https://iframe.mediadelivery.net/embed/423625/cd519102-524c-4728-a0c5-619bcdddbcd0', 'cd519102-524c-4728-a0c5-619bcdddbcd0', '2026-03-23 04:39:39'],
[97, 11, 'الجزء الثاني - 4-تطبيقات على الموجة الدافعة', 'https://iframe.mediadelivery.net/embed/423625/946fd9e5-e45f-4bb0-81d3-faf33574c6ac', '946fd9e5-e45f-4bb0-81d3-faf33574c6ac', '2026-03-23 04:39:39'],
[98, 11, 'الجزء الثاني - 5-ما هى الموجات القطرية وأنواعها وقوانينها', 'https://iframe.mediadelivery.net/embed/423625/d4ff2ace-495c-4a28-b9f1-bc2c7085d8f7', 'd4ff2ace-495c-4a28-b9f1-bc2c7085d8f7', '2026-03-23 04:39:39'],
[99, 11, 'الجزء الثاني - 6-تطبيق علي  الموجات القطرية', 'https://iframe.mediadelivery.net/embed/423625/e1543444-b5cc-499c-ac29-0fd444846290', 'e1543444-b5cc-499c-ac29-0fd444846290', '2026-03-23 04:39:39'],
[100, 11, 'الجزء الثاني - 7-ما هى التصحيحات وما هو تصحيح الزجزاج', 'https://iframe.mediadelivery.net/embed/423625/4f9da8e7-17b4-4458-81e7-2d91cc7dfa92', '4f9da8e7-17b4-4458-81e7-2d91cc7dfa92', '2026-03-23 04:39:39'],
[101, 11, 'الجزء الثاني - 8-تطبيق علي تصحيح الزجزاج', 'https://iframe.mediadelivery.net/embed/423625/15bd0e9c-230e-4f8b-9675-0e9198e1f5f6', '15bd0e9c-230e-4f8b-9675-0e9198e1f5f6', '2026-03-23 04:39:39'],
[102, 11, 'الجزء الثاني - 9-ما هو تصحيح الفلات وأنواعه وقوانينه', 'https://iframe.mediadelivery.net/embed/423625/7a70b39a-4108-4da3-bd16-b5886e81ff39', '7a70b39a-4108-4da3-bd16-b5886e81ff39', '2026-03-23 04:39:39'],
[103, 11, 'الجزء الثاني - 10-تطبيق علي تصحيح الفلات', 'https://iframe.mediadelivery.net/embed/423625/24e36510-7263-4158-bf1d-bed889eedc11', '24e36510-7263-4158-bf1d-bed889eedc11', '2026-03-23 04:39:39'],
[104, 11, 'الجزء الثاني - 11-تصحيح المثلث وقوانينه وأنواعه', 'https://iframe.mediadelivery.net/embed/423625/5661d39a-772e-4fb9-b1aa-415c873fe803', '5661d39a-772e-4fb9-b1aa-415c873fe803', '2026-03-23 04:39:39'],
[105, 11, 'الجزء الثاني - 12-تطبيق علي تصحيح المثلثات', 'https://iframe.mediadelivery.net/embed/423625/969d480d-3293-4655-a72c-c1b4a722ac6a', '969d480d-3293-4655-a72c-c1b4a722ac6a', '2026-03-23 04:39:39'],
[106, 12, 'المحاضره الاولي', 'https://iframe.mediadelivery.net/embed/423625/55af39e9-e071-48d2-81a0-c102135fbeee', '55af39e9-e071-48d2-81a0-c102135fbeee', '2026-03-23 04:39:39'],
[107, 12, 'المحاضره الثانيه', 'https://iframe.mediadelivery.net/embed/423625/237e57b1-fdc5-44cf-b563-b33c02381700', '237e57b1-fdc5-44cf-b563-b33c02381700', '2026-03-23 04:39:39'],
[108, 12, 'المحاضره الثالثه', 'https://iframe.mediadelivery.net/embed/423625/108de0ed-899b-48b7-8e7c-e96f567f3a09', '108de0ed-899b-48b7-8e7c-e96f567f3a09', '2026-03-23 04:39:39'],
[109, 12, 'المحاضره الأخيرة', 'https://iframe.mediadelivery.net/embed/423625/2cdca0a6-e0e1-4859-a285-d48f8fa4a27a', '2cdca0a6-e0e1-4859-a285-d48f8fa4a27a', '2026-03-23 04:39:39'],
[110, 13, 'مقدمة', 'https://iframe.mediadelivery.net/embed/423625/0c9c068c-c4d3-46d4-bd2b-79c8b2dbebf2', '0c9c068c-c4d3-46d4-bd2b-79c8b2dbebf2', '2026-03-23 04:39:39'],
[111, 13, 'نصائح هامه قبل البدء', 'https://iframe.mediadelivery.net/embed/423625/80dd06c6-c553-484d-b935-b0d27859d02b', '80dd06c6-c553-484d-b935-b0d27859d02b', '2026-03-23 04:39:39'],
[112, 13, 'ترتيب الآفكار اثناء دراسه المنافسين', 'https://iframe.mediadelivery.net/embed/423625/98f59c30-fe83-453e-8085-e2222896a03d', '98f59c30-fe83-453e-8085-e2222896a03d', '2026-03-23 04:39:39'],
[113, 13, 'كيفيه تحليل المنافسين على الفيس بوك 1', 'https://iframe.mediadelivery.net/embed/423625/36edf16e-8139-4228-9f6e-af06b08aeeed', '36edf16e-8139-4228-9f6e-af06b08aeeed', '2026-03-23 04:39:39'],
[114, 13, 'كيفيه تحليل المنافسين على الفيس بوك 2', 'https://iframe.mediadelivery.net/embed/423625/b851dadb-0a11-4eb6-9f64-9f15ac1f64a1', 'b851dadb-0a11-4eb6-9f64-9f15ac1f64a1', '2026-03-23 04:39:39'],
[115, 13, 'كيفيه تحليل المنافسين على الفيس بوك 3', 'https://iframe.mediadelivery.net/embed/423625/99ac7258-da23-44ac-b3e4-45775a62e773', '99ac7258-da23-44ac-b3e4-45775a62e773', '2026-03-23 04:39:39'],
[116, 13, 'تحليل الموقع الالكترونى لمنافسينك', 'https://iframe.mediadelivery.net/embed/423625/23ddad41-87a0-4996-b4a8-d2dd336c9583', '23ddad41-87a0-4996-b4a8-d2dd336c9583', '2026-03-23 04:39:39'],
[117, 13, 'تحليل منافسينك من خلال اليوتيوب', 'https://iframe.mediadelivery.net/embed/423625/aa7a2cb7-b57a-45ff-9e4d-b91ba38409ae', 'aa7a2cb7-b57a-45ff-9e4d-b91ba38409ae', '2026-03-23 04:39:39'],
[118, 13, 'أضافات هامه لمتصفح جوجل كروم', 'https://iframe.mediadelivery.net/embed/423625/85308e0e-5413-4e76-b912-ab77b95c7646', '85308e0e-5413-4e76-b912-ab77b95c7646', '2026-03-23 04:39:39'],
[119, 13, 'الخاتمه', 'https://iframe.mediadelivery.net/embed/423625/c40612eb-3bcf-48df-a224-6ed71fefd8a2', 'c40612eb-3bcf-48df-a224-6ed71fefd8a2', '2026-03-23 04:39:39'],
[120, 14, 'جولة تعريفية', 'https://iframe.mediadelivery.net/embed/423625/73bb7c29-3e64-451c-aed5-515f587ad25e', '73bb7c29-3e64-451c-aed5-515f587ad25e', '2026-03-23 04:39:39'],
[121, 14, 'LOGOعمل خلفية شفافة ل', 'https://iframe.mediadelivery.net/embed/423625/5933686b-3118-4a94-8e14-b2734c8eb035', '5933686b-3118-4a94-8e14-b2734c8eb035', '2026-03-23 04:39:39'],
[122, 14, 'تصميم غلاف فيس بوك', 'https://iframe.mediadelivery.net/embed/423625/e38fbfc5-ab60-472e-ba40-8b1f759a9ffb', 'e38fbfc5-ab60-472e-ba40-8b1f759a9ffb', '2026-03-23 04:39:39'],
[123, 14, 'LinkedIn banner تصميم', 'https://iframe.mediadelivery.net/embed/423625/f1268fb8-977f-470b-8597-214728586997', 'f1268fb8-977f-470b-8597-214728586997', '2026-03-23 04:39:39'],
[124, 14, 'والتصاميم المختلفة canva تحديث', 'https://iframe.mediadelivery.net/embed/423625/606a3f9a-ccc8-470d-9a62-92c8b9e6ec45', '606a3f9a-ccc8-470d-9a62-92c8b9e6ec45', '2026-03-23 04:39:39'],
[125, 14, 'تصميم اعلان فيسبوك احترافي', 'https://iframe.mediadelivery.net/embed/423625/1ffea70a-5074-4e21-8e6e-8328393331a7', '1ffea70a-5074-4e21-8e6e-8328393331a7', '2026-03-23 04:39:39'],
[126, 14, 'تصميم اعلان انستغرام احترافي', 'https://iframe.mediadelivery.net/embed/423625/ed94b78d-a9d3-472e-a654-ce350a3afcbe', 'ed94b78d-a9d3-472e-a654-ce350a3afcbe', '2026-03-23 04:39:39'],
[127, 14, 'تصميم اعلان ستوري', 'https://iframe.mediadelivery.net/embed/423625/f00e28cd-ef4c-4a8b-8f77-52d3910bd966', 'f00e28cd-ef4c-4a8b-8f77-52d3910bd966', '2026-03-23 04:39:39'],
[128, 14, 'تصميم اعلان سناب شات', 'https://iframe.mediadelivery.net/embed/423625/e8c872df-f72b-4d50-bf6c-0f03b1acaa82', 'e8c872df-f72b-4d50-bf6c-0f03b1acaa82', '2026-03-23 04:39:39'],
[129, 15, 'مقدمة', 'https://iframe.mediadelivery.net/embed/423625/ee7a315d-4d93-4ef2-8eb8-16baa70ae16f', 'ee7a315d-4d93-4ef2-8eb8-16baa70ae16f', '2026-03-23 04:39:39'],
[130, 15, 'الفرق بين التسويق والاقتصاد', 'https://iframe.mediadelivery.net/embed/423625/15e902af-8f2a-43d3-af82-5fd58f8bc02c', '15e902af-8f2a-43d3-af82-5fd58f8bc02c', '2026-03-23 04:39:39'],
[131, 15, 'نماذج بورتر لاعمال', 'https://iframe.mediadelivery.net/embed/423625/98687654-1678-4e27-afe7-c7cdf649a21a', '98687654-1678-4e27-afe7-c7cdf649a21a', '2026-03-23 04:39:39'],
[132, 15, 'Business Model', 'https://iframe.mediadelivery.net/embed/423625/0f2f1568-26af-479e-9b09-e861481cda48', '0f2f1568-26af-479e-9b09-e861481cda48', '2026-03-23 04:39:39'],
[133, 15, 'نصائح هامه-البزنس مودل', 'https://iframe.mediadelivery.net/embed/423625/2ef09ea8-1478-4433-9545-8a41bc1cc81c', '2ef09ea8-1478-4433-9545-8a41bc1cc81c', '2026-03-23 04:39:39'],
[134, 15, 'المحاضره رقم 3-الجزء الأول', 'https://iframe.mediadelivery.net/embed/423625/679b5a78-2410-405b-837b-b42a49b9adeb', '679b5a78-2410-405b-837b-b42a49b9adeb', '2026-03-23 04:39:39'],
[135, 15, 'المحاضره رقم 3-الجزء الثانى', 'https://iframe.mediadelivery.net/embed/423625/1b66345f-aafc-4533-b61c-d35923760d1b', '1b66345f-aafc-4533-b61c-d35923760d1b', '2026-03-23 04:39:39'],
[136, 15, 'المحاضره رقم 3-الجزء الثالث', 'https://iframe.mediadelivery.net/embed/423625/13b4868a-061b-4c18-b7d1-b773c9d4476f', '13b4868a-061b-4c18-b7d1-b773c9d4476f', '2026-03-23 04:39:39'],
[137, 15, 'المحاضره رقم 3-الجزء الرابع', 'https://iframe.mediadelivery.net/embed/423625/bd754a36-88e4-4b9b-9daa-061b98716ea7', 'bd754a36-88e4-4b9b-9daa-061b98716ea7', '2026-03-23 04:39:39'],
[138, 15, 'مقدمه عن المزيج التسويقى', 'https://iframe.mediadelivery.net/embed/423625/04f6c269-c54f-4446-bd9b-a4f08ec7887a', '04f6c269-c54f-4446-bd9b-a4f08ec7887a', '2026-03-23 04:39:39'],
[139, 15, 'المنتج', 'https://iframe.mediadelivery.net/embed/423625/22934fe2-d75e-4a13-9ba4-4a6b57b9878d', '22934fe2-d75e-4a13-9ba4-4a6b57b9878d', '2026-03-23 04:39:39'],
[140, 15, 'التسعير', 'https://iframe.mediadelivery.net/embed/423625/f1a91038-03fb-40ef-9670-3947cc7ec68c', 'f1a91038-03fb-40ef-9670-3947cc7ec68c', '2026-03-23 04:39:39'],
[141, 15, 'الترويج', 'https://iframe.mediadelivery.net/embed/423625/1bfc8fa3-832f-4c20-ace0-a86e8a4a2378', '1bfc8fa3-832f-4c20-ace0-a86e8a4a2378', '2026-03-23 04:39:39'],
[142, 15, 'المكان', 'https://iframe.mediadelivery.net/embed/423625/f8be50e7-1c51-4686-90bc-02cef188f3cc', 'f8be50e7-1c51-4686-90bc-02cef188f3cc', '2026-03-23 04:39:39'],
[143, 15, 'تقسيم السوق', 'https://iframe.mediadelivery.net/embed/423625/366af79a-cf31-47e8-bfd2-4016ab88d483', '366af79a-cf31-47e8-bfd2-4016ab88d483', '2026-03-23 04:39:39'],
[144, 15, 'تقسيم الشركات', 'https://iframe.mediadelivery.net/embed/423625/105804c3-8816-4a18-acef-881b1b753b6e', '105804c3-8816-4a18-acef-881b1b753b6e', '2026-03-23 04:39:39'],
[145, 15, 'الاستهداف', 'https://iframe.mediadelivery.net/embed/423625/1f456133-16a6-4c3b-97f5-e16da911dd3e', '1f456133-16a6-4c3b-97f5-e16da911dd3e', '2026-03-23 04:39:39'],
[146, 15, 'كيفيه عمل بحث تسويقي الجزء الاول', 'https://iframe.mediadelivery.net/embed/423625/6e852e4d-e482-4855-9801-28830958ad92', '6e852e4d-e482-4855-9801-28830958ad92', '2026-03-23 04:39:39'],
[147, 15, 'كيفيه عمل بحث تسويقي الجزء الثاني', 'https://iframe.mediadelivery.net/embed/423625/9c81a3ac-1a73-42fa-be76-d88915868235', '9c81a3ac-1a73-42fa-be76-d88915868235', '2026-03-23 04:39:39'],
[148, 15, 'التحليل الرباعى', 'https://iframe.mediadelivery.net/embed/423625/10baf81e-cc88-487b-8f0f-c0790db8f5bf', '10baf81e-cc88-487b-8f0f-c0790db8f5bf', '2026-03-23 04:39:39'],
[149, 16, 'مقدمة', 'https://iframe.mediadelivery.net/embed/423625/4c87afb2-2009-476a-8a5d-69c2599702b5', '4c87afb2-2009-476a-8a5d-69c2599702b5', '2026-03-23 04:39:39'],
[150, 16, 'ماهى المجالات المناسبة على التيك توك', 'https://iframe.mediadelivery.net/embed/423625/77e51317-2656-486c-b031-7ccaef0d4a33', '77e51317-2656-486c-b031-7ccaef0d4a33', '2026-03-23 04:39:39'],
[151, 16, 'أشكال المحتوى المناسبة لبعض من المجالات اللى تساعدك في تحقيق مبيعات', 'https://iframe.mediadelivery.net/embed/423625/ed69b38c-94b7-4195-970b-6ded05193a44', 'ed69b38c-94b7-4195-970b-6ded05193a44', '2026-03-23 04:39:39'],
[152, 16, 'تهيئة الأكونت الخاص بك على التيك توك بطريقة احترافية', 'https://iframe.mediadelivery.net/embed/423625/873822ac-cc46-47c3-83a4-102d6cfeb3c5', '873822ac-cc46-47c3-83a4-102d6cfeb3c5', '2026-03-23 04:39:39'],
[153, 16, 'أنواع المحتوى المناسبة لزيادة  التفاعل', 'https://iframe.mediadelivery.net/embed/423625/c0acdb91-285f-46e3-87bf-2194acfe4064', 'c0acdb91-285f-46e3-87bf-2194acfe4064', '2026-03-23 04:39:39'],
[154, 16, 'إعدادات تهمك لنشر محتواك على التيك توك', 'https://iframe.mediadelivery.net/embed/423625/5c488ec3-40e8-4955-a77e-a3f6c4dbdd04', '5c488ec3-40e8-4955-a77e-a3f6c4dbdd04', '2026-03-23 04:39:39'],
[155, 16, 'كيفيه الوصول الى 20 الف متابع في أقل من 30 يوم', 'https://iframe.mediadelivery.net/embed/423625/60a22df9-1205-4df7-b08b-05b20a279cc5', '60a22df9-1205-4df7-b08b-05b20a279cc5', '2026-03-23 04:39:39'],
[156, 16, 'بدء الحساب الاعلانى.', 'https://iframe.mediadelivery.net/embed/423625/be721647-3341-434c-af04-76f2ec691e28', 'be721647-3341-434c-af04-76f2ec691e28', '2026-03-23 04:39:39'],
[157, 16, 'أنواع الإعلانات على التيك توك', 'https://iframe.mediadelivery.net/embed/423625/9ca9aaa0-1c73-42eb-b030-5064794cf4ee', '9ca9aaa0-1c73-42eb-b030-5064794cf4ee', '2026-03-23 04:39:39'],
[158, 16, '1-اعلان الوصول', 'https://iframe.mediadelivery.net/embed/423625/d45f3f8e-e196-4017-b7fa-44f21d3796ba', 'd45f3f8e-e196-4017-b7fa-44f21d3796ba', '2026-03-23 04:39:39'],
[159, 16, '2-إعلان الزيارات', 'https://iframe.mediadelivery.net/embed/423625/4a014ad7-a8cb-46c0-b25f-288dbcc7d9ff', '4a014ad7-a8cb-46c0-b25f-288dbcc7d9ff', '2026-03-23 04:39:39'],
[160, 16, '3-إعلان مشاهدات الفيديو', 'https://iframe.mediadelivery.net/embed/423625/d9de3e62-9288-4263-b734-a19093416ea8', 'd9de3e62-9288-4263-b734-a19093416ea8', '2026-03-23 04:39:39'],
[161, 16, '4-إعلان تفاعل المجتمع(زيادة المتابعين)', 'https://iframe.mediadelivery.net/embed/423625/fd987ab4-7b91-4b9a-baf0-0c4d5b358513', 'fd987ab4-7b91-4b9a-baf0-0c4d5b358513', '2026-03-23 04:39:39'],
[162, 16, '5-إعلان التطبيقات', 'https://iframe.mediadelivery.net/embed/423625/668cf7d1-214a-4659-ad7e-fd7a72514c28', '668cf7d1-214a-4659-ad7e-fd7a72514c28', '2026-03-23 04:39:39'],
[163, 16, '6-إعلان العملاء المحتملين', 'https://iframe.mediadelivery.net/embed/423625/fd6ac8fb-3fc8-4818-9886-7db9362d13c4', 'fd6ac8fb-3fc8-4818-9886-7db9362d13c4', '2026-03-23 04:39:39'],
[164, 16, 'إعلان التحويل(الكونفرجين)', 'https://iframe.mediadelivery.net/embed/423625/3c1a20b3-f893-4bb5-a886-73bb065e5a1a', '3c1a20b3-f893-4bb5-a886-73bb065e5a1a', '2026-03-23 04:39:39'],
[165, 16, 'كيفيه ربط البكسل من البدايه للنهاية', 'https://iframe.mediadelivery.net/embed/423625/7a5c145a-c608-475c-af64-2acfe4c25637', '7a5c145a-c608-475c-af64-2acfe4c25637', '2026-03-23 04:39:39'],
[166, 16, 'نموذج لاعلان 1', 'https://iframe.mediadelivery.net/embed/423625/4e15d316-baf5-413b-b182-0942d1554c3f', '4e15d316-baf5-413b-b182-0942d1554c3f', '2026-03-23 04:39:39'],
[167, 16, 'نموذج لاعلان 2', 'https://iframe.mediadelivery.net/embed/423625/fda16d48-4119-44de-90cc-eb2acc7b3525', 'fda16d48-4119-44de-90cc-eb2acc7b3525', '2026-03-23 04:39:39'],
[168, 16, 'نموذج لاعلان 2', 'https://iframe.mediadelivery.net/embed/423625/b4326c58-51d6-4a87-8913-8f1125cf09a0', 'b4326c58-51d6-4a87-8913-8f1125cf09a0', '2026-03-23 04:39:39'],
[169, 16, 'نموذج لاعلان 3', 'https://iframe.mediadelivery.net/embed/423625/501b11cf-7881-49dc-abf2-8392d623418d', '501b11cf-7881-49dc-abf2-8392d623418d', '2026-03-23 04:39:39'],
[170, 16, 'نموذج لاعلان 4', 'https://iframe.mediadelivery.net/embed/423625/af3f7594-aee2-4b4a-a1ec-2704e10a467d', 'af3f7594-aee2-4b4a-a1ec-2704e10a467d', '2026-03-23 04:39:39'],
[171, 16, 'نموذج لاعلان 5', 'https://iframe.mediadelivery.net/embed/423625/bce55abf-1ce2-4b0c-bd36-5aa454f856a6', 'bce55abf-1ce2-4b0c-bd36-5aa454f856a6', '2026-03-23 04:39:39'],
[172, 16, 'نموذج لاعلان 6', 'https://iframe.mediadelivery.net/embed/423625/8e3966f7-24e8-4262-bd89-f95ed5df0dd3', '8e3966f7-24e8-4262-bd89-f95ed5df0dd3', '2026-03-23 04:39:39'],
[173, 16, 'نموذج لاعلان 7', 'https://iframe.mediadelivery.net/embed/423625/0ef11866-d9ed-42b5-88b3-4ac594cf1aac', '0ef11866-d9ed-42b5-88b3-4ac594cf1aac', '2026-03-23 04:39:39'],
[174, 16, 'نموذج لاعلان 8', 'https://iframe.mediadelivery.net/embed/423625/fe8c30cd-888a-4bd8-87ee-5f8932092a97', 'fe8c30cd-888a-4bd8-87ee-5f8932092a97', '2026-03-23 04:39:39'],
[175, 16, 'نموذج لاعلان 9', 'https://iframe.mediadelivery.net/embed/423625/910f8d2c-23a4-4b26-a79b-d372f81ca82b', '910f8d2c-23a4-4b26-a79b-d372f81ca82b', '2026-03-23 04:39:39']
];

DB::beginTransaction();

try {
    $chaptersCache = [];
    
    foreach ($videos as $video) {
        $id = $video[0];
        $course_id = $video[1];
        $title = $video[2];
        $video_url = $video[3];
        $bunny_video_id = $video[4];
        $created_at = $video[5];
        
        // 1. Get or Create Course Chapter
        if (!isset($chaptersCache[$course_id])) {
            $chapter = CourseChapter::firstOrCreate(
                ['course_id' => $course_id],
                [
                    'user_id' => 1, // Defaulting to generic admin user
                    // 'title' => 'محتوى الكورس ' . $course_id,
                    'title' => 'محتويات الكورس',
                    'slug' => Str::slug('محتويات الكورس-' . $course_id . '-' . uniqid()),
                    'is_active' => 1,
                    'chapter_order' => 1,
                    'type' => 'lecture', // In case type exists, maybe it doesn't wait
                ]
            );
            $chaptersCache[$course_id] = $chapter->id;
        }
        
        $chapter_id = $chaptersCache[$course_id];
        
        // 2. Insert into course_chapter_lectures
        DB::table('course_chapter_lectures')->insert([
            // 'id' => $id,  // better to let it autoincrement in case of clashes, but we can set it
            'user_id' => 1, 
            'course_chapter_id' => $chapter_id,
            'title' => $title,
            'slug' => Str::slug($title . '-' . uniqid()),
            'type' => 'youtube_url',
            'youtube_url' => $video_url,
            // 'description' => 'Bunny ID: ' . $bunny_video_id, // Store bunny id in description just in case
            'chapter_order' => $id, // Use original id for ordering
            'is_active' => 1,
            'free_preview' => 0,
            'is_free' => 0,
            'created_at' => $created_at,
            'updated_at' => $created_at,
        ]);
    }

    DB::commit();
    echo "Successfully inserted " . count($videos) . " videos into course_chapter_lectures!";
} catch (\Exception $e) {
    DB::rollBack();
    echo "Error: " . $e->getMessage();
}
