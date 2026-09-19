CREATE TABLE "admins" (
	"email" text PRIMARY KEY NOT NULL,
	"name" text DEFAULT '' NOT NULL,
	"role" text DEFAULT 'editor' NOT NULL,
	"added_at" timestamp with time zone DEFAULT now() NOT NULL,
	"added_by" text DEFAULT '' NOT NULL
);
--> statement-breakpoint
CREATE TABLE "app_settings" (
	"key" text NOT NULL,
	"value" jsonb NOT NULL,
	"updated_at" timestamp with time zone DEFAULT now() NOT NULL,
	"updated_by" text DEFAULT '' NOT NULL,
	CONSTRAINT "app_settings_key_pk" PRIMARY KEY("key")
);
--> statement-breakpoint
CREATE TABLE "audit_log" (
	"id" bigserial PRIMARY KEY NOT NULL,
	"at" timestamp with time zone DEFAULT now() NOT NULL,
	"actor" text NOT NULL,
	"action" text NOT NULL,
	"detail" jsonb DEFAULT '{}'::jsonb NOT NULL,
	"ip" text,
	"user_agent" text
);
--> statement-breakpoint
CREATE TABLE "draft" (
	"id" integer PRIMARY KEY NOT NULL,
	"base_version" integer NOT NULL,
	"data" jsonb NOT NULL,
	"updated_at" timestamp with time zone DEFAULT now() NOT NULL,
	"updated_by" text NOT NULL
);
--> statement-breakpoint
CREATE TABLE "imports" (
	"id" bigserial PRIMARY KEY NOT NULL,
	"received_at" timestamp with time zone DEFAULT now() NOT NULL,
	"source" text NOT NULL,
	"file_name" text NOT NULL,
	"from_email" text,
	"status" text NOT NULL,
	"profile_signature" text,
	"sheet_name" text,
	"result" jsonb,
	"error" text,
	"applied_version" integer,
	"decided_by" text,
	"decided_at" timestamp with time zone,
	"rows" jsonb
);
--> statement-breakpoint
CREATE TABLE "quotes" (
	"id" text PRIMARY KEY NOT NULL,
	"created_at" timestamp with time zone DEFAULT now() NOT NULL,
	"version" integer NOT NULL,
	"dest_id" text NOT NULL,
	"service_id" text NOT NULL,
	"type" text NOT NULL,
	"billable_g" integer NOT NULL,
	"total" integer NOT NULL,
	"detail" jsonb NOT NULL,
	"booked" boolean DEFAULT false NOT NULL
);
--> statement-breakpoint
CREATE TABLE "versions" (
	"id" bigserial PRIMARY KEY NOT NULL,
	"version" integer NOT NULL,
	"published_at" timestamp with time zone DEFAULT now() NOT NULL,
	"published_by" text NOT NULL,
	"source" text NOT NULL,
	"summary" text NOT NULL,
	"change_count" integer DEFAULT 0 NOT NULL,
	"data" jsonb NOT NULL
);
--> statement-breakpoint
CREATE INDEX "audit_log_at_idx" ON "audit_log" USING btree ("at");--> statement-breakpoint
CREATE INDEX "imports_status_idx" ON "imports" USING btree ("status","received_at");--> statement-breakpoint
CREATE INDEX "quotes_created_idx" ON "quotes" USING btree ("created_at");--> statement-breakpoint
CREATE UNIQUE INDEX "versions_version_idx" ON "versions" USING btree ("version");