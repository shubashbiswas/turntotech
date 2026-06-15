import { revalidatePath, revalidateTag } from "next/cache";
import { NextRequest, NextResponse } from "next/server";

export const maxDuration = 30;

interface RevalidateWebhookPayload {
  target: {
    type: "post" | "page" | "taxonomy";
    slug: string;
    id: number | null;
  };
  event: string;
  timestamp: number;
}

/**
 * WordPress webhook handler for targeted content revalidation
 * Listens to async payloads from WP-Cron and resets matching pathways.
 */
export async function POST(request: NextRequest) {
  try {
    const requestBody: RevalidateWebhookPayload = await request.json();
    const secret = request.headers.get("x-webhook-secret");

    // 1. Authentication Layer Guard
    if (secret !== process.env.WORDPRESS_WEBHOOK_SECRET) {
      console.error("Invalid webhook secret");
      return NextResponse.json(
        { message: "Invalid webhook secret" },
        { status: 401 }
      );
    }

    const { target, event } = requestBody;

    if (!target || !target.type) {
      return NextResponse.json(
        { message: "Missing target structure or payload configuration parameters" },
        { status: 400 }
      );
    }

    const contentType = target.type;
    const contentId = target.id;
    const slug = target.slug;

    console.log(`Processing async payload [${event}] for specific targets -> Type: ${contentType} | Slug: ${slug}`);

    // 2. Clear Global Wrapper Cache Tags (Fixed: Added required second argument)
    revalidateTag("wordpress");

    // 3. Isolated Target Invalidation Logic
    if (contentType === "post") {
      revalidateTag("posts");
      revalidateTag("posts-page-1"); // Wipe just primary post feed references
      
      if (contentId) {
        revalidateTag(`post-${contentId}`);
      }
      
      // Clear ONLY this single post profile path
      if (slug) {
        revalidatePath(`/blog/${slug}`);
      }

    } else if (contentType === "page") {
      revalidateTag("pages");
      
      if (contentId) {
        revalidateTag(`page-${contentId}`);
      }
      
      // Clear ONLY this single standalone page path
      if (slug) {
        revalidatePath(`/${slug}`);
      }

    } else if (contentType === "taxonomy") {
      // Handles localized adjustments to categories or post tags
      revalidateTag("categories");
      revalidateTag("tags");

      if (contentId) {
        revalidateTag(`posts-category-${contentId}`);
        revalidateTag(`category-${contentId}`);
        revalidateTag(`posts-tag-${contentId}`);
        revalidateTag(`tag-${contentId}`);
      }
      
      // Refresh the specific post file containing changed taxonomies
      if (slug) {
        revalidatePath(`/blog/${slug}`);
      }
    }

    // 4. Update index view safely without destroying site layout components
    revalidatePath("/", "page"); 

    // Explicit HTTP 200 array response required for WP-Cron acknowledgement
    return NextResponse.json(
      { revalidated: true, target: slug, execution: "partial" }, 
      { status: 200 }
    );

  } catch (error) {
    console.error("Revalidation pipeline unexpected catch loop error:", error);
    return NextResponse.json(
      {
        revalidated: false,
        message: "Failed execution process during revalidation run",
        error: (error as Error).message,
      },
      { status: 500 }
    );
  }
}