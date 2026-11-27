# Covisca UI refresh — phase 1
Shared authenticated shell, overview and orders. Other pages retain their current workflows and scoped compatibility styling. Public landing unchanged.
- Six workspaces and visible contextual links; no nested menu. Curtain costs remain a product-specific action.
- TailAdmin-inspired original components and tokens, no licensed Pro assets or framework CDN. Standalone CSS is used by the current PHP pages. Optional Tailwind v4 input is ui/tailwind.css; compile locally using tailwindcss@4 and @tailwindcss/cli@4, inspect build and run browser tests before using it. Do not overwrite the source CSS with the build.
- KPI: sales, estimated order profit, sale packages, estimated margin. Orders filter affects only table; period totals cover the selected date range.
- Do not claim a final net profit, fabricate stock alerts or treat package count as order count. Keep evidence and all expense details available.
- Layout: 24/16/12px spacing, 44px controls, focus visible, statuses with text.
- Next scope: dedicated product variant, tariff selection and finance report interactions; full accessibility and mobile audit. No production deployment without approval.
