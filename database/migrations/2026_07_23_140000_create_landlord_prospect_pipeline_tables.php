<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('landlord_prospects', function (Blueprint $table): void {
            $table->id();
            $table->string('business_name', 160);
            $table->string('contact_name', 160)->nullable();
            $table->string('trade', 80)->index();
            $table->string('county', 80)->index();
            $table->string('city', 100)->nullable();
            $table->string('website', 500)->nullable();
            $table->string('email', 255)->nullable()->index();
            $table->string('phone', 80)->nullable();
            $table->string('status', 60)->default('new')->index();
            $table->string('source', 120)->nullable();
            $table->text('notes')->nullable();
            $table->timestamp('last_contacted_at')->nullable()->index();
            $table->timestamp('responded_at')->nullable()->index();
            $table->timestamp('next_follow_up_at')->nullable()->index();
            $table->unsignedBigInteger('converted_tenant_id')->nullable()->index();
            $table->timestamp('converted_at')->nullable();
            $table->unsignedBigInteger('created_by_user_id')->nullable()->index();
            $table->timestamps();

            $table->foreign('converted_tenant_id')
                ->references('id')
                ->on('tenants')
                ->nullOnDelete();
            $table->foreign('created_by_user_id')
                ->references('id')
                ->on('users')
                ->nullOnDelete();
        });

        Schema::create('landlord_prospect_communications', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('landlord_prospect_id')->index();
            $table->string('direction', 30)->default('note')->index();
            $table->string('channel', 30)->default('email')->index();
            $table->string('status', 40)->default('logged')->index();
            $table->string('subject', 255)->nullable();
            $table->text('body')->nullable();
            $table->string('from_address', 255)->nullable();
            $table->string('to_address', 255)->nullable();
            $table->string('external_message_id', 255)->nullable()->index();
            $table->timestamp('occurred_at')->index();
            $table->unsignedBigInteger('created_by_user_id')->nullable()->index();
            $table->timestamps();

            $table->foreign('landlord_prospect_id')
                ->references('id')
                ->on('landlord_prospects')
                ->cascadeOnDelete();
            $table->foreign('created_by_user_id')
                ->references('id')
                ->on('users')
                ->nullOnDelete();
        });

        $now = now();
        DB::table('landlord_prospects')->insert([
            [
                'business_name' => 'R&R Lawn LLC',
                'contact_name' => null,
                'trade' => 'Landscaping',
                'county' => 'Pickens',
                'city' => 'Easley',
                'website' => 'https://www.rrlawn.org/',
                'email' => 'rrlawneasley@gmail.com',
                'phone' => '864-402-9333',
                'status' => 'draft_ready',
                'source' => 'Company website',
                'notes' => 'Family-owned and operated. Strong fit for scheduling, recurring maintenance, job notes, crews, and customer follow-up.',
                'created_at' => $now,
                'updated_at' => $now,
            ],
            [
                'business_name' => 'Dominguez Landscaping LLC',
                'contact_name' => null,
                'trade' => 'Landscaping',
                'county' => 'Pickens',
                'city' => 'Easley',
                'website' => 'https://www.dominguezlandscaping.net/',
                'email' => 'landscaping7090@gmail.com',
                'phone' => '864-884-4448',
                'status' => 'draft_ready',
                'source' => 'Company website',
                'notes' => 'Local landscaping and lawn-care company. Potential fit for quote intake, job scheduling, photos, notes, and customer updates.',
                'created_at' => $now,
                'updated_at' => $now,
            ],
            [
                'business_name' => 'SC Wired',
                'contact_name' => 'Ryan Becker',
                'trade' => 'Electrical',
                'county' => 'Pickens / Greenville',
                'city' => 'Upstate',
                'website' => 'https://www.scwired.com/',
                'email' => 'ryan@scwired.com',
                'phone' => '864-207-1542',
                'status' => 'draft_ready',
                'source' => 'Company website',
                'notes' => 'Owner-operated electrical company serving both target counties. Strong fit for estimates, jobs, parts, photos, and customer communication.',
                'created_at' => $now,
                'updated_at' => $now,
            ],
            [
                'business_name' => 'APEL Electrical',
                'contact_name' => 'Jeffrey',
                'trade' => 'Electrical',
                'county' => 'Greenville',
                'city' => 'Simpsonville',
                'website' => 'https://www.apelelectrical.com/',
                'email' => 'jeffrey@apelelectrical.com',
                'phone' => '864-686-2735',
                'status' => 'draft_ready',
                'source' => 'Company website',
                'notes' => 'Family-owned residential and commercial electrician. Potential fit for intake, estimates, scheduling, job tracking, and follow-up.',
                'created_at' => $now,
                'updated_at' => $now,
            ],
            [
                'business_name' => 'Agee HVAC LLC',
                'contact_name' => 'Adrian',
                'trade' => 'HVAC',
                'county' => 'Greenville',
                'city' => 'Greenville',
                'website' => 'https://www.ageehvac.com/',
                'email' => 'ageehvac.llc@gmail.com',
                'phone' => '864-897-9161',
                'status' => 'draft_ready',
                'source' => 'Company website',
                'notes' => 'Local owner-led HVAC company. Strong fit for service calls, maintenance plans, scheduling, estimates, and repeat-customer reminders.',
                'created_at' => $now,
                'updated_at' => $now,
            ],
            [
                'business_name' => 'Rhino HVAC',
                'contact_name' => null,
                'trade' => 'HVAC',
                'county' => 'Greenville',
                'city' => 'Greenville',
                'website' => 'https://www.gorhinohvac.com/',
                'email' => 'GoRhinoHVAC@gmail.com',
                'phone' => '864-990-7659',
                'status' => 'draft_ready',
                'source' => 'Company website',
                'notes' => 'Local 24/7 HVAC company. Potential fit for lead intake, dispatch, service history, estimates, and maintenance follow-up.',
                'created_at' => $now,
                'updated_at' => $now,
            ],
            [
                'business_name' => 'Warmer Water & Plumbing',
                'contact_name' => null,
                'trade' => 'Plumbing',
                'county' => 'Greenville',
                'city' => 'Simpsonville',
                'website' => 'https://warmerwater.com/',
                'email' => 'office@warmerwater.com',
                'phone' => '864-966-2444',
                'status' => 'draft_ready',
                'source' => 'Company website',
                'notes' => 'Family-owned plumbing company serving Greenville, Powdersville, Pickens, and Easley. Strong cross-county launch-partner fit.',
                'created_at' => $now,
                'updated_at' => $now,
            ],
            [
                'business_name' => "CJ's Plumbing",
                'contact_name' => 'Cedric',
                'trade' => 'Plumbing',
                'county' => 'Greenville',
                'city' => 'Greenville',
                'website' => 'https://cjsplumbing.org/',
                'email' => 'info@cjplbg.com',
                'phone' => '864-373-8276',
                'status' => 'draft_ready',
                'source' => 'Company website',
                'notes' => 'Family-owned residential and commercial plumber. Potential fit for dispatch, job history, estimates, and follow-up.',
                'created_at' => $now,
                'updated_at' => $now,
            ],
            [
                'business_name' => 'Beemer KangaRoof',
                'contact_name' => null,
                'trade' => 'Roofing',
                'county' => 'Greenville',
                'city' => 'Greenville',
                'website' => 'https://www.beemerkangaroof.com/',
                'email' => 'hello@beemerkangaroof.com',
                'phone' => '864-412-2573',
                'status' => 'draft_ready',
                'source' => 'Company website',
                'notes' => 'Local Upstate roofer. Potential fit for inspection leads, estimates, project stages, insurance documentation, photos, and customer updates.',
                'created_at' => $now,
                'updated_at' => $now,
            ],
            [
                'business_name' => 'Turn Key Roofing',
                'contact_name' => null,
                'trade' => 'Roofing',
                'county' => 'Pickens / Greenville',
                'city' => 'Greenville',
                'website' => 'https://www.turnkeyroofing.net/',
                'email' => 'sales@turnkeyroofing.net',
                'phone' => '864-241-8133',
                'status' => 'draft_ready',
                'source' => 'Company website',
                'notes' => 'Serves both target counties. Potential fit for inspections, estimates, project stages, documentation, and lead follow-up.',
                'created_at' => $now,
                'updated_at' => $now,
            ],
        ]);

        $bookingUrl = 'https://calendar.google.com/calendar/u/0/appointments/schedules/AcZssZ3Oj4ptqrCIm0a2aVd1ud7GtVJszMBUYKIqCYME5NP7YnoUr16UtKpslsPJNs2b117OnQqce7X0';
        $drafts = [
            'rrlawneasley@gmail.com' => [
                'greeting' => 'Hi R&R Lawn team,',
                'opening' => 'I came across your family-owned lawn and landscaping company in Easley, and I liked the story behind the R&R name.',
                'fit' => 'For a landscaping company, that could mean one simple place for recurring work, schedules, customer notes, crews, job photos, estimates, and follow-up.',
            ],
            'landscaping7090@gmail.com' => [
                'greeting' => 'Hi Dominguez Landscaping team,',
                'opening' => 'I came across your Easley landscaping business while looking for strong local trade companies in Pickens County.',
                'fit' => 'For a landscaping company, that could mean one simple place for quote requests, schedules, customer notes, job photos, crew assignments, and follow-up.',
            ],
            'ryan@scwired.com' => [
                'greeting' => 'Hi Ryan,',
                'opening' => 'I came across SC Wired while looking at owner-led trade companies serving both Pickens and Greenville counties.',
                'fit' => 'For an electrical company, that could mean one simple place for estimates, jobs, parts, customer notes, photos, scheduling, and follow-up.',
            ],
            'jeffrey@apelelectrical.com' => [
                'greeting' => 'Hi Jeffrey,',
                'opening' => 'I came across APEL Electrical and appreciated the family-owned story and the focus on both residential and commercial work.',
                'fit' => 'For an electrical company, that could mean one simple place for intake, estimates, jobs, parts, customer notes, scheduling, and follow-up.',
            ],
            'ageehvac.llc@gmail.com' => [
                'greeting' => 'Hi Adrian,',
                'opening' => 'I came across Agee HVAC and liked the owner-led, straightforward approach your customer reviews describe.',
                'fit' => 'For an HVAC company, that could mean one simple place for service calls, maintenance plans, estimates, scheduling, equipment history, and customer reminders.',
            ],
            'GoRhinoHVAC@gmail.com' => [
                'greeting' => 'Hi Rhino HVAC team,',
                'opening' => 'I came across Rhino HVAC while looking at local Greenville trade companies that are growing around fast, reliable service.',
                'fit' => 'For an HVAC company, that could mean one simple place for lead intake, dispatch, estimates, equipment history, service notes, and maintenance follow-up.',
            ],
            'office@warmerwater.com' => [
                'greeting' => 'Hi Warmer Water team,',
                'opening' => 'I came across Warmer Water & Plumbing and liked that you are family-owned and already serve Greenville, Powdersville, Pickens, and Easley.',
                'fit' => 'For a plumbing company, that could mean one simple place for service calls, scheduling, estimates, job history, customer notes, and follow-up.',
            ],
            'info@cjplbg.com' => [
                'greeting' => 'Hi Cedric,',
                'opening' => "I came across CJ's Plumbing and appreciated the family-business story and your mix of residential and commercial work.",
                'fit' => 'For a plumbing company, that could mean one simple place for dispatch, estimates, job history, customer notes, scheduling, and follow-up.',
            ],
            'hello@beemerkangaroof.com' => [
                'greeting' => 'Hi Beemer KangaRoof team,',
                'opening' => 'I came across your Greenville roofing team while looking for strong local trade businesses across the Upstate.',
                'fit' => 'For a roofing company, that could mean one simple place for inspection leads, estimates, project stages, insurance documentation, photos, and customer updates.',
            ],
            'sales@turnkeyroofing.net' => [
                'greeting' => 'Hi Turn Key Roofing team,',
                'opening' => 'I came across Turn Key Roofing while looking for established trade companies serving both Pickens and Greenville counties.',
                'fit' => 'For a roofing company, that could mean one simple place for inspections, estimates, project stages, documentation, photos, and lead follow-up.',
            ],
        ];

        foreach ($drafts as $email => $draft) {
            $prospectId = DB::table('landlord_prospects')->where('email', $email)->value('id');
            if (! is_numeric($prospectId)) {
                continue;
            }

            $businessName = (string) DB::table('landlord_prospects')->where('id', $prospectId)->value('business_name');
            $body = implode("\n\n", [
                $draft['greeting'],
                "I’m John, founder of Evergrove Software here in Powdersville. {$draft['opening']}",
                'I started Evergrove because my own company, Modern Forestry, needed specific software that nothing on the market handled well. After 11 years running that candle business, I know what it feels like to pay for software that still makes you work around it.',
                "I build simpler, customizable software for local businesses—usually for less than the systems they’re paying for now—and shape it around how the team actually works. {$draft['fit']}",
                'We currently have 8 of 10 launch-partner spots open. Launch partners get direct access to me and help shape the software around their day-to-day operation.',
                "I’m happy to drive to you, meet at my office in Powdersville, or start with a 30-minute Google Meet. You can pick a time here:\n{$bookingUrl}",
                'Would it be worth a short conversation to see where your current tools are getting in the way?',
                "Thanks,\nJohn Collins\nFounder, Evergrove Software\njohn@evergrovesoftware.com\ninstagram.com/evergrovesoftware",
            ]);

            DB::table('landlord_prospect_communications')->insert([
                'landlord_prospect_id' => (int) $prospectId,
                'direction' => 'outbound',
                'channel' => 'email',
                'status' => 'draft',
                'subject' => 'A local software idea for '.$businessName,
                'body' => $body,
                'from_address' => 'john@evergrovesoftware.com',
                'to_address' => $email,
                'occurred_at' => $now,
                'created_at' => $now,
                'updated_at' => $now,
            ]);
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('landlord_prospect_communications');
        Schema::dropIfExists('landlord_prospects');
    }
};
