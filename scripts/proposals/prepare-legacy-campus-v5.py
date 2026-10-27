"""Normalize ONLY the fixed signed public v5 archive before candidate activation.

No runtime path/options/ENV/config/SQL/network interface. The importable core is
for private fixture composition; main supplies fixed real candidate admission.
"""
import hashlib
import json
import os
import pwd
import re
import stat
import sys

SITE = '/home/forge/backstage.theforestrystudio.com'
INDEX_SHA = 'b71565febc5f6e65e728a149259ea4b41ffcfbd5e48512e76576c6253d27c5fd'
DEFINITION_SHA = 'b9a2f4a64908e2a4d608c144297b9bb458378cd69dafd0c9bf08cabb32395de7'
DEFINITION_BYTES = 31069
FILES = {'index.json': {'sha256': 'b71565febc5f6e65e728a149259ea4b41ffcfbd5e48512e76576c6253d27c5fd', 'bytes': 23245}, 'source/apps/enterprise/public/build/assets/voice-jobs-entry-CI-oK47m.js': {'sha256': 'f80c83f51e33cdeffc082a39d6d7a24066690f2a0c4c28f2b356c253d951c3be', 'bytes': 11378}, 'source/apps/enterprise/public/build/assets/workspace-CX6uujIm.css': {'sha256': '1b1549231e1947cc1e9a93239d49a86f5bb44ee7881ff3856c3b98ce09157cd3', 'bytes': 23899}, 'source/apps/enterprise/public/build/assets/workspace-DyBZPYb_.js': {'sha256': '2fbd58d6aef8bff34b080968a3be34ac2d514136a900ebe0113999adbc239ec0', 'bytes': 368504}, 'source/apps/enterprise/public/build/manifest.json': {'sha256': '7926e96712664f36a09e11be14b52d340d7408bfcff426e553a49d2a80ba0784', 'bytes': 468}, 'source/resources/data/enterprise/campus-map-points.json': {'sha256': '8ffc5109e1b9772a1ed7bb91b2e5dada8c246cc272cc621c620f9f253c5c0826', 'bytes': 4468}, 'source/resources/data/enterprise/lees-mcrae-locations.json': {'sha256': '71d604a832a6ebf9e7e4e5203eb62b11aea54322471d394f456ddd99de85e287', 'bytes': 13474}, 'source/resources/data/enterprise/official-campus-plan.png': {'sha256': 'f7c01925bafd8edceae3ef7719eef60f73d563d7d1cbc460990266c87e997345', 'bytes': 1144741}, 'source/resources/images/enterprise/Everbranch-Rooted-Peak-Aligned.png': {'sha256': 'e3c1a81bd7e87062db375d59023c3db2c286d1df6de6868f41147d155047d1e2', 'bytes': 98790}, 'source/resources/images/enterprise/Everbranch-Rooted-Peak-Aligned.svg': {'sha256': '1b3055f835ab349c7a206947ceeafb0f1203cb9f99ce67f3b54808e242c44e78', 'bytes': 12750}, 'source/resources/js/enterprise/campus-review.css': {'sha256': '46c721a73de447385ab65c01a906db67f0af7e95945a129fb4a9b7f7d86c17d3', 'bytes': 2895}, 'source/resources/js/enterprise/campus-review.js': {'sha256': '489207eef9474ff9769166195b20bd587fada725c2fe944499f16360e48080fc', 'bytes': 13244}, 'source/resources/js/enterprise/detail-placement.js': {'sha256': '5689ece993a1a4cc3370393be4f526ada21c604123af8c71680c87ace708f5f7', 'bytes': 1382}, 'source/resources/js/enterprise/reviewer/assets/lees-mcrae-official-wordmark.png': {'sha256': '53d4a05658f82f60ec5860a046da59f8bfba79aa78fc3efa025c80df91f29e17', 'bytes': 15283}, 'source/resources/js/enterprise/reviewer/executive.js': {'sha256': '19bb11be02fedfc38785a2aa960234f10e5b335ce58b8f1eae0a0841aa9fdfc8', 'bytes': 17695}, 'source/resources/js/enterprise/reviewer/job-sample.js': {'sha256': '28d35250b2e7dd1967ab1897fa0b962e0c581f0b540fe07f4465cf82a797e4b3', 'bytes': 9740}, 'source/resources/js/enterprise/reviewer/sample-adapter.js': {'sha256': '173c9f0f04f36c539a9a919c1760fa8698f9d6dd0a9a7797512990b56c22cea7', 'bytes': 210634}, 'source/resources/js/enterprise/reviewer/tour.js': {'sha256': 'c782cb4c8d649bb3c6f99f094ad120b635f7e070e1010288a665bf7cc8d17729', 'bytes': 36752}, 'source/resources/js/enterprise/reviewer/workspace-tour.js': {'sha256': 'b76c0e62538ef7d775800a946b451016436679a4d8e1f65fae9dd81831c380ac', 'bytes': 25408}, 'source/resources/js/enterprise/roll-call-projects.js': {'sha256': '9e167f6e69db9b13b9591fbcbbd2309b1ad460143ffdc3cff20dfc6aa29b282c', 'bytes': 3764}, 'source/resources/js/enterprise/transport.js': {'sha256': '3f56d221ab00e00e4a6b2a9d8aa55d61dddd486c5a48450f2360528e7a4449b5', 'bytes': 4697}, 'source/resources/js/enterprise/usage.js': {'sha256': '24490305891b02dd6261b9c82e59920660837dd5f93a2a04804c6ba32d0aab27', 'bytes': 24558}, 'source/resources/js/enterprise/work-views.js': {'sha256': '39388d2a678d0883f40c7554dae8b028fbb3aec7c992c30d6c9075dca96900a3', 'bytes': 5510}, 'source/resources/js/service-proposals/activity.js': {'sha256': 'fdf0cd4ca359666fb43181e7c96f40ce5f9f3d14deb670fbe976bfa6a195f4ba', 'bytes': 2605}, 'source/resources/views/enterprise/app-install-head.blade.php': {'sha256': '1cb1e8dc0056df2aef98f5f6fd70f694c2c6e733df2bbb42ca730839893ae46c', 'bytes': 539}, 'source/resources/views/enterprise/app-install.blade.php': {'sha256': '2272b654ca455c6fa6baa1c98777c9ea878d7e312396301a8ae0f0945507db7d', 'bytes': 8356}, 'source/resources/views/enterprise/app.blade.php': {'sha256': 'ef9f85f54e785279bdc5514b2c3ecbe07992c9b4e6625262385e06831f8f22ae', 'bytes': 60199}, 'source/resources/views/enterprise/campus-review-assets.blade.php': {'sha256': '9caf02d95d17f32b0153f7e8e61cfe4eaaa4c6b4df70ddad47f8139587cc91b4', 'bytes': 397}, 'source/resources/views/enterprise/campus-styles.blade.php': {'sha256': 'c6e4ece99f41bc01e961a3b49930e276dd22242097762f629cffd1b9380fca1c', 'bytes': 41073}, 'source/resources/views/enterprise/conversation-controls.blade.php': {'sha256': 'd1b55d2f8e7e622c45fe16f5f3d0b044be873d43b2986c1937cefc189531787e', 'bytes': 2203}, 'source/resources/views/enterprise/conversation-script.blade.php': {'sha256': 'de18f8e5341e4b82cc8beacbffa80bacc4a6a871f4efc01e214de879ea9e88ab', 'bytes': 22790}, 'source/resources/views/enterprise/enterprise-campus-theme.blade.php': {'sha256': '5c397c57104288f37924a6539b3ff01491824244b86cf0f8e38db87729195b29', 'bytes': 551}, 'source/resources/views/enterprise/executive-demo.blade.php': {'sha256': 'ecbd42403d53f7aeb753f741cfdaad9ce2e42bee65726211226f8304787b6e7a', 'bytes': 4850}, 'source/resources/views/enterprise/finance-script.blade.php': {'sha256': '6a2350c91b3260ea81abd445d74da7d717e9d3aa4f330177a090519905da3304', 'bytes': 19806}, 'source/resources/views/enterprise/finance-workspace.blade.php': {'sha256': '0c5c47f8e871ff493fca0241991c8c4f73235e0d06199eba737bef2daf917b37', 'bytes': 5369}, 'source/resources/views/enterprise/home-script.blade.php': {'sha256': 'dde6537696c2d2c7b5394cda8f2c1b2f4d410265fa405b8fd01e5f15fefb9b66', 'bytes': 6582}, 'source/resources/views/enterprise/home.blade.php': {'sha256': 'cd0b6a2042d25f8318507cf801f9b65b86d64bb80550d6b89363f930e2d10178', 'bytes': 1506}, 'source/resources/views/enterprise/next-panels.blade.php': {'sha256': 'fa63aa6e9530dda43625a21d720c2bb2b29cf33629f0819ea020e8d546e1bcfa', 'bytes': 5845}, 'source/resources/views/enterprise/next-script.blade.php': {'sha256': 'bf8d8ebb8bf13a72d7f10a235e7a4a010a9c705b1674345553b49ee8da2a3968', 'bytes': 29626}, 'source/resources/views/enterprise/next-styles.blade.php': {'sha256': '4f974bef955f0ca441283d44df9fa1d54f1d5bab8181a51f5bd33e8c462049cf', 'bytes': 7990}, 'source/resources/views/enterprise/operations-nav.blade.php': {'sha256': 'ec3303fe60373679fc403e60e2eafbb7d2961f8ae6ff60cee93f74573bbab4bd', 'bytes': 780}, 'source/resources/views/enterprise/operations-panels.blade.php': {'sha256': '3e08ce1947737f3aaf83a31bbad5ae6098e3ef234ac9b03fff4a31a371780ac5', 'bytes': 7116}, 'source/resources/views/enterprise/operations-script.blade.php': {'sha256': '2dd04cf9fa115d039b01f183150ed72160853d83e3fcc880df20728b815785b4', 'bytes': 41436}, 'source/resources/views/enterprise/partials/usage-overview.blade.php': {'sha256': '2b7d47aebaca495428227ba8e78ff3335323ccce29034b88093c620ddb821d8a', 'bytes': 3472}, 'source/resources/views/enterprise/private-memory-controls.blade.php': {'sha256': '323dc42d8d793336a78f6beb0ea349101ed12fbb7c906856dc61c61c81c4e07e', 'bytes': 3098}, 'source/resources/views/enterprise/private-memory-script.blade.php': {'sha256': '0f37eacb24831f27996804415e5b1533192e96b7e2ccbfade73e2c61ec06b99c', 'bytes': 14064}, 'source/resources/views/enterprise/procedures-script.blade.php': {'sha256': 'd711fb9ef7a5099b611ac5cbab454740f78a263efc42694f5b76b7a2d4b3040a', 'bytes': 38053}, 'source/resources/views/enterprise/procedures-workspace.blade.php': {'sha256': '0c80080d6ddb8b7bd305dd67ae2c9a572506621abfb6c3370c9eb0bf91ef4e36', 'bytes': 8672}, 'source/resources/views/enterprise/project-detail-script.blade.php': {'sha256': 'baae1c9d74272090f7bfe2f6d17501c0fd6e8ece48efa79b48ee944ccf63b155', 'bytes': 3903}, 'source/resources/views/enterprise/read-only-assistant-script.blade.php': {'sha256': 'eb54d5008241c561c3362e1a5fb060fbcc19a7d631bdbb90e14109bfba8dbd2c', 'bytes': 7379}, 'source/resources/views/enterprise/read-only-assistant.blade.php': {'sha256': 'dccf718769044084ff8792f3647c3e312d423e96a894fddf5ba7dcfdc4619f2d', 'bytes': 1276}, 'source/resources/views/enterprise/resources-panels.blade.php': {'sha256': '4f1602dbd683f03a76335d3ce58ba7fbf5f4280abb10b0ad7f1850670f6b119d', 'bytes': 16873}, 'source/resources/views/enterprise/resources-script.blade.php': {'sha256': 'ebcd81b25a6fcc3d0610e252a42f97d2a812af1003f6854597fc9e3b4ad6a686', 'bytes': 40255}, 'source/resources/views/enterprise/reviewer-adapter.blade.php': {'sha256': '8ff054350720b332ca54b863240ead8f741e5a13c10f1c940d620937e34b27dc', 'bytes': 8162}, 'source/resources/views/enterprise/reviewer-tour.blade.php': {'sha256': '02cff77cb80e6a6fec702ab827a5e7603ee8e80ba0a38c81fe6b7a0edfb79f2c', 'bytes': 24681}, 'source/resources/views/enterprise/role-preview.blade.php': {'sha256': '44cd80cdbacd33b6f368a19374a1779eb1dbd308efaaf695d05f2dd340f7123b', 'bytes': 15477}, 'source/resources/views/enterprise/roll-call-panels.blade.php': {'sha256': '2a5e9d343c3e5152da03f6624aec11bc9f5dfc4151dfa644ab15f4415a05ce29', 'bytes': 13276}, 'source/resources/views/enterprise/roll-call-script.blade.php': {'sha256': '310e6486059e97046912d24b79e74bd007a746454103390122860f5916c736fc', 'bytes': 37704}, 'source/resources/views/enterprise/runtime-designation.blade.php': {'sha256': '9e39a33f8ac33abc6c6102ea15df668a77c8c0b23c0e637b24fb06598c86a979', 'bytes': 633}, 'source/resources/views/enterprise/saved-assistant-controls.blade.php': {'sha256': '897df172ba25d91be8ea06ebf07ca528fa236ef1ea4a563f4d51725f6d5f6c47', 'bytes': 3004}, 'source/resources/views/enterprise/saved-assistant-script.blade.php': {'sha256': '7fb8d7e5fe9d1106cb6b14891f8922be669270a0f3a7e8d84d6393922f6a3475', 'bytes': 11148}, 'source/resources/views/enterprise/usage-script.blade.php': {'sha256': '968330b65917222a59f01fa312ed79018dbe1f2c3ad301ec48f45051f6bf1496', 'bytes': 2120}, 'source/resources/views/enterprise/voice-pane.blade.php': {'sha256': 'a774ffbbff7757db6c0f603550e619c37bac0882b9d9cd13c0adba421eceb41c', 'bytes': 2181}, 'source/resources/views/enterprise/voice-script.blade.php': {'sha256': 'eb83bc662ef2ff6e5cbd2b6ac1f3d692b9863e43cfa4698c96862d058a34853c', 'bytes': 10662}, 'source/resources/views/enterprise/work-detail-panel.blade.php': {'sha256': 'b0b30152c2635714adc7f04414ac91564ecca6c1c23636b228e00c54fb675ec9', 'bytes': 4589}, 'source/resources/views/enterprise/work-detail-script.blade.php': {'sha256': '79fb6cfb21cf1329b4e9afbcb068ac107ab05797e41df5fe1a88f1fbed23dc28', 'bytes': 1138}, 'source/resources/views/enterprise/work-management-panels.blade.php': {'sha256': 'b3a05c5b198476626d4a58fd267ee17032b24cf932358542ab4520aeb159b979', 'bytes': 2827}, 'source/resources/views/enterprise/work-management-script.blade.php': {'sha256': '071dda0430680a59fbfa4bc852fc22b37237d0cb0f2dc4af355b0d90f3ef6963', 'bytes': 12129}, 'source/resources/views/enterprise/work-views-script.blade.php': {'sha256': '2d4e4c7cda70dbab0334558ab231323d8ba1a8c8ae1a89bc50ac0798c0d007bf', 'bytes': 9760}, 'source/resources/views/enterprise/work-views-styles.blade.php': {'sha256': '2b9d5afd4d4bed1e3678b6dd0d6cdc72bf075a65810cb12faac498c7218d320a', 'bytes': 4018}, 'source/resources/views/enterprise/workflow-priorities.blade.php': {'sha256': 'ce41572855ea1907618d86094c226d627d9e1d8e6d2023488d2f5f28ef192e4f', 'bytes': 3561}, 'source/resources/views/enterprise/workspace-tour.blade.php': {'sha256': 'a30c259415cba33fd5c7881a0b768b361bdc5055c4570a954ba20eae60a2b210', 'bytes': 807}, 'source/resources/views/enterprise/workspace.blade.php': {'sha256': '8a7300da8f0bb3e9d83ab15813f94a3c15ea61c3978459d7025d8da0df61bf22', 'bytes': 2810}, 'source/resources/views/service-proposals/activity-bridge.blade.php': {'sha256': 'caeaa741df5f8a95bf23c7abce778761c5bc11d7c3ca9a289e331298dfa9400b', 'bytes': 1021}, 'source/resources/views/service-proposals/campus-review.blade.php': {'sha256': '93f84866a102e0791eee0c08d601489bcaede419e32d792a30f512f0d2b36654', 'bytes': 5073}, 'views/enterprise/app-install-head.blade.php': {'sha256': '1cb1e8dc0056df2aef98f5f6fd70f694c2c6e733df2bbb42ca730839893ae46c', 'bytes': 539}, 'views/enterprise/app-install.blade.php': {'sha256': '2272b654ca455c6fa6baa1c98777c9ea878d7e312396301a8ae0f0945507db7d', 'bytes': 8356}, 'views/enterprise/app.blade.php': {'sha256': 'f8befd1ee1379fa767b1a39d66d429b63c2a8dd671d55833e3f1a38e830afb8c', 'bytes': 60543}, 'views/enterprise/campus-review-assets.blade.php': {'sha256': '73416c6cfae3c4064c3a6cdb312e14658b1ae3c2003b870d52e0f5e7e344c4ee', 'bytes': 415}, 'views/enterprise/campus-styles.blade.php': {'sha256': 'c6e4ece99f41bc01e961a3b49930e276dd22242097762f629cffd1b9380fca1c', 'bytes': 41073}, 'views/enterprise/conversation-controls.blade.php': {'sha256': '725c4d07cd1837b2914581ef0a07ac9cce0e19ec666a12f661c4d198ded011d7', 'bytes': 2225}, 'views/enterprise/conversation-script.blade.php': {'sha256': 'de18f8e5341e4b82cc8beacbffa80bacc4a6a871f4efc01e214de879ea9e88ab', 'bytes': 22790}, 'views/enterprise/enterprise-campus-theme.blade.php': {'sha256': '5c397c57104288f37924a6539b3ff01491824244b86cf0f8e38db87729195b29', 'bytes': 551}, 'views/enterprise/executive-demo.blade.php': {'sha256': '618a06447559cc9b8e13db0d7072476ffa37f52a52561fc431da7f892b06d22c', 'bytes': 4848}, 'views/enterprise/finance-script.blade.php': {'sha256': '6a2350c91b3260ea81abd445d74da7d717e9d3aa4f330177a090519905da3304', 'bytes': 19806}, 'views/enterprise/finance-workspace.blade.php': {'sha256': '0c5c47f8e871ff493fca0241991c8c4f73235e0d06199eba737bef2daf917b37', 'bytes': 5369}, 'views/enterprise/home-script.blade.php': {'sha256': 'dde6537696c2d2c7b5394cda8f2c1b2f4d410265fa405b8fd01e5f15fefb9b66', 'bytes': 6582}, 'views/enterprise/home.blade.php': {'sha256': 'cd0b6a2042d25f8318507cf801f9b65b86d64bb80550d6b89363f930e2d10178', 'bytes': 1506}, 'views/enterprise/next-panels.blade.php': {'sha256': '60c97a06438babd08118ddbdc0d77509e3531c35398c4aced56bc57bb7509298', 'bytes': 5878}, 'views/enterprise/next-script.blade.php': {'sha256': '72d567617343eed4acd60200baa0b529ebd55feffd1594cb4ba86b476f6bcdf7', 'bytes': 29670}, 'views/enterprise/next-styles.blade.php': {'sha256': '4f974bef955f0ca441283d44df9fa1d54f1d5bab8181a51f5bd33e8c462049cf', 'bytes': 7990}, 'views/enterprise/operations-nav.blade.php': {'sha256': 'ec3303fe60373679fc403e60e2eafbb7d2961f8ae6ff60cee93f74573bbab4bd', 'bytes': 780}, 'views/enterprise/operations-panels.blade.php': {'sha256': '5336f22d1798aa14ebfce8d8c4445227aefc776119048dfd139accca51b22934', 'bytes': 7138}, 'views/enterprise/operations-script.blade.php': {'sha256': 'e84c60a6ea43692e0b507276e40014eb9f244592b58c43ef45f8bb1232ecadd3', 'bytes': 41458}, 'views/enterprise/partials/usage-overview.blade.php': {'sha256': '2b7d47aebaca495428227ba8e78ff3335323ccce29034b88093c620ddb821d8a', 'bytes': 3472}, 'views/enterprise/private-memory-controls.blade.php': {'sha256': '323dc42d8d793336a78f6beb0ea349101ed12fbb7c906856dc61c61c81c4e07e', 'bytes': 3098}, 'views/enterprise/private-memory-script.blade.php': {'sha256': '0f37eacb24831f27996804415e5b1533192e96b7e2ccbfade73e2c61ec06b99c', 'bytes': 14064}, 'views/enterprise/procedures-script.blade.php': {'sha256': 'd711fb9ef7a5099b611ac5cbab454740f78a263efc42694f5b76b7a2d4b3040a', 'bytes': 38053}, 'views/enterprise/procedures-workspace.blade.php': {'sha256': '0c80080d6ddb8b7bd305dd67ae2c9a572506621abfb6c3370c9eb0bf91ef4e36', 'bytes': 8672}, 'views/enterprise/project-detail-script.blade.php': {'sha256': 'baae1c9d74272090f7bfe2f6d17501c0fd6e8ece48efa79b48ee944ccf63b155', 'bytes': 3903}, 'views/enterprise/read-only-assistant-script.blade.php': {'sha256': 'eb54d5008241c561c3362e1a5fb060fbcc19a7d631bdbb90e14109bfba8dbd2c', 'bytes': 7379}, 'views/enterprise/read-only-assistant.blade.php': {'sha256': 'dccf718769044084ff8792f3647c3e312d423e96a894fddf5ba7dcfdc4619f2d', 'bytes': 1276}, 'views/enterprise/resources-panels.blade.php': {'sha256': '6ab784fdaf8d383428943bcba7e526193f3bcdb9de5cfe501830d10789d94224', 'bytes': 16871}, 'views/enterprise/resources-script.blade.php': {'sha256': 'ebcd81b25a6fcc3d0610e252a42f97d2a812af1003f6854597fc9e3b4ad6a686', 'bytes': 40255}, 'views/enterprise/reviewer-adapter.blade.php': {'sha256': '3801397299017282c193b938899107331f605a09bcf31fddc0eced43c2727073', 'bytes': 8156}, 'views/enterprise/reviewer-tour.blade.php': {'sha256': '492765cb797c28354ca0456c0ecf044585e7535ac0b66a7031722888795a281c', 'bytes': 24679}, 'views/enterprise/role-preview.blade.php': {'sha256': '44cd80cdbacd33b6f368a19374a1779eb1dbd308efaaf695d05f2dd340f7123b', 'bytes': 15477}, 'views/enterprise/roll-call-panels.blade.php': {'sha256': '2a5e9d343c3e5152da03f6624aec11bc9f5dfc4151dfa644ab15f4415a05ce29', 'bytes': 13276}, 'views/enterprise/roll-call-script.blade.php': {'sha256': 'edfb46651110d3044d12941ecea096cce4f3a6a4618bb6d3c13e584771a60d3d', 'bytes': 37702}, 'views/enterprise/runtime-designation.blade.php': {'sha256': '9e39a33f8ac33abc6c6102ea15df668a77c8c0b23c0e637b24fb06598c86a979', 'bytes': 633}, 'views/enterprise/saved-assistant-controls.blade.php': {'sha256': '897df172ba25d91be8ea06ebf07ca528fa236ef1ea4a563f4d51725f6d5f6c47', 'bytes': 3004}, 'views/enterprise/saved-assistant-script.blade.php': {'sha256': '7fb8d7e5fe9d1106cb6b14891f8922be669270a0f3a7e8d84d6393922f6a3475', 'bytes': 11148}, 'views/enterprise/usage-script.blade.php': {'sha256': '968330b65917222a59f01fa312ed79018dbe1f2c3ad301ec48f45051f6bf1496', 'bytes': 2120}, 'views/enterprise/voice-pane.blade.php': {'sha256': 'a774ffbbff7757db6c0f603550e619c37bac0882b9d9cd13c0adba421eceb41c', 'bytes': 2181}, 'views/enterprise/voice-script.blade.php': {'sha256': 'eb83bc662ef2ff6e5cbd2b6ac1f3d692b9863e43cfa4698c96862d058a34853c', 'bytes': 10662}, 'views/enterprise/work-detail-panel.blade.php': {'sha256': 'b0b30152c2635714adc7f04414ac91564ecca6c1c23636b228e00c54fb675ec9', 'bytes': 4589}, 'views/enterprise/work-detail-script.blade.php': {'sha256': 'a36f69f606ea7879291436b8f4e1ee0fdffbb8efdbb605b001d6677b15d43354', 'bytes': 1136}, 'views/enterprise/work-management-panels.blade.php': {'sha256': 'b3a05c5b198476626d4a58fd267ee17032b24cf932358542ab4520aeb159b979', 'bytes': 2827}, 'views/enterprise/work-management-script.blade.php': {'sha256': '071dda0430680a59fbfa4bc852fc22b37237d0cb0f2dc4af355b0d90f3ef6963', 'bytes': 12129}, 'views/enterprise/work-views-script.blade.php': {'sha256': '16f9eafc71376a2cdb58278bbb0ed6a000b60124785898a07a466bcc6aab02a4', 'bytes': 9756}, 'views/enterprise/work-views-styles.blade.php': {'sha256': '2b9d5afd4d4bed1e3678b6dd0d6cdc72bf075a65810cb12faac498c7218d320a', 'bytes': 4018}, 'views/enterprise/workflow-priorities.blade.php': {'sha256': '2ba53b30be445ff483381eee9ec63d66eb783cb396bced31e1e8a7e7b54a3926', 'bytes': 3570}, 'views/enterprise/workspace-tour.blade.php': {'sha256': '298189a4edb8e013a77011356af78763aba802b79952bd9d54a4289a16a8fa42', 'bytes': 814}, 'views/enterprise/workspace.blade.php': {'sha256': '1a8cc9dce0c6b977a0946a56ef032375d20509a240473ab909da621fba0997a1', 'bytes': 2865}, 'views/service-proposals/activity-bridge.blade.php': {'sha256': 'a7bfbb1725ed32d49b7c352a79590feffabd14b1f48345c3f068d516cc542bb7', 'bytes': 1017}, 'views/service-proposals/campus-review.blade.php': {'sha256': '93f84866a102e0791eee0c08d601489bcaede419e32d792a30f512f0d2b36654', 'bytes': 5073}}
DIRECTORIES = ['.', 'source', 'views', 'source/apps', 'source/resources', 'views/enterprise', 'views/service-proposals', 'source/apps/enterprise', 'source/resources/data', 'source/resources/images', 'source/resources/js', 'source/resources/views', 'views/enterprise/partials', 'source/apps/enterprise/public', 'source/resources/data/enterprise', 'source/resources/images/enterprise', 'source/resources/js/enterprise', 'source/resources/js/service-proposals', 'source/resources/views/enterprise', 'source/resources/views/service-proposals', 'source/apps/enterprise/public/build', 'source/resources/js/enterprise/reviewer', 'source/resources/views/enterprise/partials', 'source/apps/enterprise/public/build/assets', 'source/resources/js/enterprise/reviewer/assets']
CHILDREN = {'.': ['index.json', 'source', 'views'], 'source': ['apps', 'resources'], 'views': ['enterprise', 'service-proposals'], 'source/apps': ['enterprise'], 'source/resources': ['data', 'images', 'js', 'views'], 'views/enterprise': ['app-install-head.blade.php', 'app-install.blade.php', 'app.blade.php', 'campus-review-assets.blade.php', 'campus-styles.blade.php', 'conversation-controls.blade.php', 'conversation-script.blade.php', 'enterprise-campus-theme.blade.php', 'executive-demo.blade.php', 'finance-script.blade.php', 'finance-workspace.blade.php', 'home-script.blade.php', 'home.blade.php', 'next-panels.blade.php', 'next-script.blade.php', 'next-styles.blade.php', 'operations-nav.blade.php', 'operations-panels.blade.php', 'operations-script.blade.php', 'partials', 'private-memory-controls.blade.php', 'private-memory-script.blade.php', 'procedures-script.blade.php', 'procedures-workspace.blade.php', 'project-detail-script.blade.php', 'read-only-assistant-script.blade.php', 'read-only-assistant.blade.php', 'resources-panels.blade.php', 'resources-script.blade.php', 'reviewer-adapter.blade.php', 'reviewer-tour.blade.php', 'role-preview.blade.php', 'roll-call-panels.blade.php', 'roll-call-script.blade.php', 'runtime-designation.blade.php', 'saved-assistant-controls.blade.php', 'saved-assistant-script.blade.php', 'usage-script.blade.php', 'voice-pane.blade.php', 'voice-script.blade.php', 'work-detail-panel.blade.php', 'work-detail-script.blade.php', 'work-management-panels.blade.php', 'work-management-script.blade.php', 'work-views-script.blade.php', 'work-views-styles.blade.php', 'workflow-priorities.blade.php', 'workspace-tour.blade.php', 'workspace.blade.php'], 'views/service-proposals': ['activity-bridge.blade.php', 'campus-review.blade.php'], 'source/apps/enterprise': ['public'], 'source/resources/data': ['enterprise'], 'source/resources/images': ['enterprise'], 'source/resources/js': ['enterprise', 'service-proposals'], 'source/resources/views': ['enterprise', 'service-proposals'], 'views/enterprise/partials': ['usage-overview.blade.php'], 'source/apps/enterprise/public': ['build'], 'source/resources/data/enterprise': ['campus-map-points.json', 'lees-mcrae-locations.json', 'official-campus-plan.png'], 'source/resources/images/enterprise': ['Everbranch-Rooted-Peak-Aligned.png', 'Everbranch-Rooted-Peak-Aligned.svg'], 'source/resources/js/enterprise': ['campus-review.css', 'campus-review.js', 'detail-placement.js', 'reviewer', 'roll-call-projects.js', 'transport.js', 'usage.js', 'work-views.js'], 'source/resources/js/service-proposals': ['activity.js'], 'source/resources/views/enterprise': ['app-install-head.blade.php', 'app-install.blade.php', 'app.blade.php', 'campus-review-assets.blade.php', 'campus-styles.blade.php', 'conversation-controls.blade.php', 'conversation-script.blade.php', 'enterprise-campus-theme.blade.php', 'executive-demo.blade.php', 'finance-script.blade.php', 'finance-workspace.blade.php', 'home-script.blade.php', 'home.blade.php', 'next-panels.blade.php', 'next-script.blade.php', 'next-styles.blade.php', 'operations-nav.blade.php', 'operations-panels.blade.php', 'operations-script.blade.php', 'partials', 'private-memory-controls.blade.php', 'private-memory-script.blade.php', 'procedures-script.blade.php', 'procedures-workspace.blade.php', 'project-detail-script.blade.php', 'read-only-assistant-script.blade.php', 'read-only-assistant.blade.php', 'resources-panels.blade.php', 'resources-script.blade.php', 'reviewer-adapter.blade.php', 'reviewer-tour.blade.php', 'role-preview.blade.php', 'roll-call-panels.blade.php', 'roll-call-script.blade.php', 'runtime-designation.blade.php', 'saved-assistant-controls.blade.php', 'saved-assistant-script.blade.php', 'usage-script.blade.php', 'voice-pane.blade.php', 'voice-script.blade.php', 'work-detail-panel.blade.php', 'work-detail-script.blade.php', 'work-management-panels.blade.php', 'work-management-script.blade.php', 'work-views-script.blade.php', 'work-views-styles.blade.php', 'workflow-priorities.blade.php', 'workspace-tour.blade.php', 'workspace.blade.php'], 'source/resources/views/service-proposals': ['activity-bridge.blade.php', 'campus-review.blade.php'], 'source/apps/enterprise/public/build': ['assets', 'manifest.json'], 'source/resources/js/enterprise/reviewer': ['assets', 'executive.js', 'job-sample.js', 'sample-adapter.js', 'tour.js', 'workspace-tour.js'], 'source/resources/views/enterprise/partials': ['usage-overview.blade.php'], 'source/apps/enterprise/public/build/assets': ['voice-jobs-entry-CI-oK47m.js', 'workspace-CX6uujIm.css', 'workspace-DyBZPYb_.js'], 'source/resources/js/enterprise/reviewer/assets': ['lees-mcrae-official-wordmark.png']}


class Hold(Exception):
    pass


def need(value):
    if not value:
        raise Hold()


def signature(s):
    return (s.st_dev, s.st_ino, s.st_uid, s.st_gid, s.st_mode, s.st_nlink,
            s.st_size, s.st_mtime_ns, s.st_ctime_ns)


def identity_without_mode_ctime(s):
    return (s.st_dev, s.st_ino, s.st_uid, s.st_gid, s.st_nlink, s.st_size, s.st_mtime_ns)


def metadata(path, directory, uid, gid):
    s = os.lstat(path)
    need(os.path.realpath(path) == path and not stat.S_ISLNK(s.st_mode)
         and (stat.S_ISDIR(s.st_mode) if directory else stat.S_ISREG(s.st_mode))
         and s.st_uid == uid and s.st_gid == gid
         and stat.S_IMODE(s.st_mode) in ((0o755, 0o775) if directory else (0o644, 0o664))
         and (directory or s.st_nlink == 1))
    return s


def checked_open(path, directory, uid, gid):
    before = metadata(path, directory, uid, gid)
    flags = os.O_RDONLY | os.O_NOFOLLOW | os.O_NONBLOCK
    if directory:
        flags |= os.O_DIRECTORY
    fd = os.open(path, flags)
    try:
        need(signature(os.fstat(fd)) == signature(before)
             and signature(metadata(path, directory, uid, gid)) == signature(before))
        return fd, before
    except BaseException:
        os.close(fd)
        raise


def file_bytes(fd, size, digest):
    need(0 <= size <= 20000000)
    os.lseek(fd, 0, os.SEEK_SET)
    total = 0
    result = hashlib.sha256()
    while True:
        chunk = os.read(fd, min(65536, size + 1 - total))
        if not chunk:
            break
        total += len(chunk)
        need(total <= size)
        result.update(chunk)
    need(total == size and result.hexdigest() == digest)


def directory_names(fd, relative):
    names = set()
    allowed = set(CHILDREN[relative])
    with os.scandir(fd) as entries:
        for entry in entries:
            need(entry.name in allowed and entry.name not in names)
            names.add(entry.name)
    need(names == allowed)


def verify_item(item, uid, gid):
    need(signature(metadata(item['path'], item['directory'], uid, gid)) == signature(item['stat'])
         and signature(os.fstat(item['fd'])) == signature(item['stat']))
    if item['directory']:
        directory_names(item['fd'], item['relative'])
    else:
        file_bytes(item['fd'], item['bytes'], item['sha256'])
    need(signature(metadata(item['path'], item['directory'], uid, gid)) == signature(item['stat'])
         and signature(os.fstat(item['fd'])) == signature(item['stat']))


def harden(release_root, uid, gid, guard, progress, checkpoint=lambda label: None, exact_initial=False):
    """No writer except fchmod; all151 targets fully authenticated BEFORE first change.

    Cooperating context/snapshot checks are not arbitrary same-UID atomic CAS.
    Failure may be partial: caller reports unknown, never retries or rolls back.
    """
    opened = []
    try:
        guard()
        need(os.path.realpath(release_root) == release_root and not os.path.islink(release_root))
        artifact = release_root + '/resources/legacy-campus/v5'
        definition = release_root + '/resources/legacy-campus/v5-definition.json'
        fd, s = checked_open(definition, False, uid, gid)
        defined = {'fd': fd, 'stat': s, 'path': definition, 'directory': False,
                   'relative': None, 'bytes': DEFINITION_BYTES, 'sha256': DEFINITION_SHA}
        opened.append(defined)
        file_bytes(fd, DEFINITION_BYTES, DEFINITION_SHA)
        seen = {(s.st_dev, s.st_ino)}
        for relative in DIRECTORIES:
            path = artifact if relative == '.' else artifact + '/' + relative
            fd, s = checked_open(path, True, uid, gid)
            item = {'fd': fd, 'stat': s, 'path': path, 'directory': True, 'relative': relative}
            opened.append(item)
            need((s.st_dev, s.st_ino) not in seen)
            seen.add((s.st_dev, s.st_ino))
            directory_names(fd, relative)
        for relative, expected in FILES.items():
            fd, s = checked_open(artifact + '/' + relative, False, uid, gid)
            item = dict(expected, fd=fd, stat=s, path=artifact + '/' + relative,
                        directory=False, relative=relative)
            opened.append(item)
            need((s.st_dev, s.st_ino) not in seen and s.st_size == expected['bytes'])
            seen.add((s.st_dev, s.st_ino))
            file_bytes(fd, expected['bytes'], expected['sha256'])
        need(len(FILES) == 126 and len(DIRECTORIES) == 25 and len(opened) == 152)
        targets = opened[1:]
        if exact_initial:
            need(all(stat.S_IMODE(x['stat'].st_mode) == (0o775 if x['directory'] else 0o664) for x in targets))
        checkpoint('validated')
        # Recheck the complete byte/metadata/name snapshot before the first chmod.
        for item in opened:
            verify_item(item, uid, gid)
        guard()
        ordered = [x for x in targets if not x['directory']] + sorted(
            [x for x in targets if x['directory']], key=lambda x: (-(0 if x['relative'] == '.' else x['relative'].count('/') + 1), x['relative']), reverse=False)
        for item in ordered:
            guard()
            verify_item(item, uid, gid)
            mode = stat.S_IMODE(item['stat'].st_mode)
            target = 0o755 if item['directory'] else 0o644
            need(target == mode or target == (mode & ~stat.S_IWGRP))
            if mode != target:
                progress['may_have_changed'] = True
                os.fchmod(item['fd'], target)  # Sole mutation: remove group-write only.
                os.fsync(item['fd'])
                after = os.fstat(item['fd'])
                need(identity_without_mode_ctime(after) == identity_without_mode_ctime(item['stat'])
                     and stat.S_IMODE(after.st_mode) == target)
                item['stat'] = after
                verify_item(item, uid, gid)
                progress['directories_changed' if item['directory'] else 'files_changed'] += 1
                checkpoint('changed')
        for item in opened:
            verify_item(item, uid, gid)
        guard()
        return {'state': 'public_legacy_v5_metadata_ready', 'files_verified': 126,
                'directories_verified': 25, 'files_changed': progress['files_changed'],
                'directories_changed': progress['directories_changed'], 'content_or_owner_changed': False}
    finally:
        for item in reversed(opened):
            os.close(item['fd'])


def main():
    progress = {'files_changed': 0, 'directories_changed': 0, 'may_have_changed': False}
    try:
        need(len(sys.argv) == 1 and sys.flags.isolated == 1 and sys.flags.dont_write_bytecode == 1
             and os.geteuid() == 1000 and os.getegid() == 1000 and pwd.getpwuid(1000).pw_name == 'forge')
        script = os.path.abspath(__file__)
        need(os.path.realpath(script) == script and not os.path.islink(script)
             and script.endswith('/scripts/proposals/prepare-legacy-campus-v5.py'))
        candidate = script[:-len('/scripts/proposals/prepare-legacy-campus-v5.py')]
        need(re.fullmatch(re.escape(SITE) + r'/releases/[0-9]+', candidate) is not None)
        def guard():
            active = os.path.realpath(SITE + '/current')
            need(re.fullmatch(re.escape(SITE) + r'/releases/[0-9]+', active) is not None
                 and os.path.isdir(active) and active != candidate
                 and os.path.realpath(candidate) == candidate)
        result = harden(candidate, 1000, 1000, guard, progress)
        print(json.dumps(result, separators=(',', ':')))
        return 0
    except BaseException:
        print(json.dumps({'state': 'PUBLIC_LEGACY_METADATA_HOLD', 'automatic_retry': False,
                          'readback_required': True, 'may_have_changed': progress['may_have_changed'],
                          'verified_files_changed': progress['files_changed'],
                          'verified_directories_changed': progress['directories_changed']}, separators=(',', ':')))
        return 1


if __name__ == '__main__':
    sys.exit(main())
