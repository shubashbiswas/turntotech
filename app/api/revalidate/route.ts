import { revalidatePath, revalidateTag } from "next/cache";
import { NextRequest, NextResponse } from "next/server";

export const maxDuration = 30;

/**
 * WordPress webhook handler for content revalidation
 * Receives notifications from WordPress when content changes
 * and revalidates the entire site
 */

export async function POST(request: NextRequest) {
  try {
    const requestBody = await request.json();
    const secret = request.headers.get("x-webhook-secret");

    if (secret !== process.env.WORDPRESS_WEBHOOK_SECRET) {
      console.error("Invalid webhook secret");
      return NextResponse.json(
        { message: "Invalid webhook secret" },
        { status: 401 }
      );
    }

    // Normalize payload from different WordPress revalidation plugins
    // Most plugins send: { post_type: "post", id: 123, slug: "hello-world" }
    // Some send:        { contentType: "post", contentId: 123 }
    // Others send:      { type: "post", ID: 123 }
    const contentType =
      requestBody.contentType || requestBody.post_type || requestBody.type;
    const contentId = requestBody.contentId ?? requestBody.id ?? requestBody.ID ?? requestBody.post_id ?? null;
    const slug = requestBody.slug || null;

    if (!contentType) {
      // Log the received payload for debugging when content type is missing
      console.error(
        "Missing content type in webhook payload. Received:",
        JSON.stringify(requestBody)
      );
      return NextResponse.json(
        {
          message: "Missing content type",
          received: requestBody,
          note: "Expected fields: contentType, post_type, or type",
        },
        { status: 400 }
      );
    }

    try {
      console.log(
        `Revalidating content: ${contentType}${
          contentId ? ` (ID: ${contentId})` : ""
        }${slug ? ` (slug: ${slug})` : ""}`
      );

      // Revalidate all WordPress content
      revalidateTag("wordpress", { expire: 0 });

      if (contentType === "post") {
        revalidateTag("posts", { expire: 0 });
        if (contentId) {
          revalidateTag(`post-${contentId}`, { expire: 0 });
        }
        // Clear all post pages when any post changes
        revalidateTag("posts-page-1", { expire: 0 });
      } else if (contentType === "page") {
        revalidateTag("pages", { expire: 0 });
        if (contentId) {
          revalidateTag(`page-${contentId}`, { expire: 0 });
        }
      } else if (contentType === "category") {
        revalidateTag("categories", { expire: 0 });
        if (contentId) {
          revalidateTag(`posts-category-${contentId}`, { expire: 0 });
          revalidateTag(`category-${contentId}`, { expire: 0 });
        }
      } else if (contentType === "tag") {
        revalidateTag("tags", { expire: 0 });
        if (contentId) {
          revalidateTag(`posts-tag-${contentId}`, { expire: 0 });
          revalidateTag(`tag-${contentId}`, { expire: 0 });
        }
      } else if (contentType === "author" || contentType === "user") {
        revalidateTag("authors", { expire: 0 });
        if (contentId) {
          revalidateTag(`posts-author-${contentId}`, { expire: 0 });
          revalidateTag(`author-${contentId}`, { expire: 0 });
        }
      } else {
        // Unknown content type - revalidate everything
        console.log(`Unknown content type "${contentType}", revalidating all`);
        revalidateTag("wordpress", { expire: 0 });
        revalidateTag("posts", { expire: 0 });
        revalidateTag("pages", { expire: 0 });
        revalidateTag("categories", { expire: 0 });
        revalidateTag("tags", { expire: 0 });
        revalidateTag("authors", { expire: 0 });
      }

      // Also revalidate the entire layout for safety
      revalidatePath("/", "layout");

      return NextResponse.json({
        revalidated: true,
        message: `Revalidated ${contentType}${
          contentId ? ` (ID: ${contentId})` : ""
        } and related content`,
        timestamp: new Date().toISOString(),
      });
    } catch (error) {
      console.error("Error revalidating path:", error);
      return NextResponse.json(
        {
          revalidated: false,
          message: "Failed to revalidate site",
          error: (error as Error).message,
          timestamp: new Date().toISOString(),
        },
        { status: 500 }
      );
    }
  } catch (error) {
    console.error("Revalidation error:", error);
    return NextResponse.json(
      {
        message: "Error revalidating content",
        error: (error as Error).message,
        timestamp: new Date().toISOString(),
      },
      { status: 500 }
    );
  }
}