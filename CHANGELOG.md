# Changelog

All notable changes to FFL Funnels Addons are documented in this file.

## [1.5.1] - 2026-02-22

### 🎯 Features - Interactive AI Chat & One-Click Rule Creation

- **Interactive AI workflow**: Removed auto-rule creation, now fully conversational
  - AI asks clarifying questions when multiple options found ("Which Glock model?")
  - User controls every step, no automatic actions
  - Chat waits for user confirmation before creating rules

- **Smart product filtering**: AI suggestions based on store inventory
  - Web search results filtered to products you actually have
  - Only recommends products confirmed in store
  - Never suggests unavailable items

- **One-click rule creation**: New "Create Rule" button in chat
  - AI generates rule JSON with complete metadata
  - Button appears when AI suggests a rule
  - Click to create rule as inactive draft
  - User reviews in editor before activation

### 🔧 Technical - Backend & Frontend Improvements

- **New AJAX endpoint**: `woobooster_ai_create_rule()`
  - Accepts rule data from frontend
  - Creates rules via existing logic
  - Returns rule ID and editor URL

- **Enhanced message parsing**: Frontend now detects rule suggestions
  - Finds `[RULE]...[/RULE]` blocks in AI messages
  - Extracts JSON rule data automatically
  - Renders "Create This Rule" button with data

- **Improved CSS styling**
  - New button styles matching design system
  - Info message type for feedback
  - Proper spacing and typography

### 🐛 Bugs Fixed

- **WordPress sidebar menu now always visible**
  - Fixed CSS selector to ensure "FFL Funnels" menu never hidden
  - Module dropdowns work correctly

### 📋 Known Improvements Over v1.5.0

- ✅ No more auto-rule creation
- ✅ User controls each step
- ✅ Only suggests products in inventory
- ✅ One-click rule creation from chat
- ✅ Safe draft-based workflow

## [1.5.0] - 2026-02-22

### 🎯 Features - AI Chat Assistant Complete Rewrite

- **Multi-turn tool orchestration**: Proper while-loop supports 8+ sequential tool calls with parallel execution
  - Previous: Only first tool call executed per turn (blocking search_store → search_web chains)
  - Now: Full sequential and parallel tool support with up to 8 turns

- **FFL-specific domain knowledge**: Enhanced system prompt
  - Includes firearms terminology, caliber compatibility, holster types, optics mounting standards
  - Caliber/caliber mappings, product type categories
  - Better reasoning about cross-sell/upsell opportunities for FFL stores

- **Smart tool workflow**: AI orchestration improvements
  - Search store for products → Search web for compatibility → Create rule with real products
  - Tool step feedback: Shows "Searching store for holsters...", "Searching web for compatibility...", etc.

- **Draft rule creation**: Security & review-focused
  - AI-generated rules now created as **inactive (status=0)** by default
  - Store owners must manually activate after review
  - Reduces risk of incorrect recommendations going live immediately

- **Direct editor redirect**: Improved UX
  - Rules redirect to edit form instead of full page reload
  - Faster iteration and adjustment workflow

- **Better UX feedback**: Tool step indicators
  - Visual icons for each tool (search store, search web, get rules, create rule)
  - Shows intermediate progress during long operations
  - Reduces user confusion during multi-step AI requests

### 🔧 Technical - Backend Refactoring

- **Proper conditions/actions saving** (Bug fix)
  - **Previous bug**: `conditions[]` and `actions[]` arrays were silently ignored during create/update
  - Rules only saved main table but not child tables (`wp_woobooster_rule_conditions`, `wp_woobooster_rule_actions`)
  - Now: Calls `WooBooster_Rule::save_conditions()` and `WooBooster_Rule::save_actions()` correctly

- **Refactored `ajax_ai_generate()`**: ~400 → ~900 lines with proper abstraction
  - Extracted tool execution into separate methods: `ai_tool_search_store()`, `ai_tool_search_web()`, `ai_tool_get_rules()`, `ai_tool_create_rule()`, `ai_tool_update_rule()`
  - Separated system prompt building: `build_ai_system_prompt()`
  - Separated tool schema definition: `get_ai_tools()`
  - Cleaner error handling with defensive checks

- **Improved error handling**
  - Defensive `is_object()` checks on transient data
  - Better error messages for OpenAI/Tavily API failures
  - Proper handling of rate limits and connection errors

### 🔒 Security

- **XSS vulnerability fixed**
  - User messages now use `textContent` (no HTML injection possible)
  - Assistant messages use `innerHTML` but pre-escaped by server with `wp_kses_post()`
  - No more direct string concatenation in DOM

### 🎨 UI/UX

- **Improved suggestion prompts**: FFL-context examples
  - "Recommend holsters for the Glock 19"
  - "Cross-sell safety gear with 9mm ammo"
  - "Suggest optics for AR-15 rifles"
  - "Cleaning kits & cases for shotguns"
  - (Previous: Very specific firearm models only)

- **Sparkle AI icon**: Better visual identity
  - Replaced generic dollar sign ($) icon
  - New icon better represents "AI" / "magic" / "automation"

- **Clear Chat button**: Added to modal header
  - One-click conversation reset
  - Maintains focus on current task

- **System message styling**
  - Success messages: Green background with checkmark-like styling
  - Error messages: Red background for visibility
  - Better visual distinction from chat messages

### 📦 Code Quality

- **Removed inline styles in JS**: All moved to CSS
  - Eliminated `style.opacity`, `style.cursor`, `style.height` manipulations
  - Cleaner JavaScript, maintainability improved

- **New CSS utilities**: Added to `woobooster-module.css`
  - `.wb-ai-steps` and `.wb-ai-step`: Tool step container and items
  - `.wb-ai-system-msg`: System message styling
  - `.wb-ai-modal__clear`: Clear button styling
  - `.wb-ai-modal__header-actions`: Header action container

### 🚀 Performance

- **No performance regression**
  - AI operations are user-initiated (admin-only)
  - Tool loop max 8 turns prevents infinite loops
  - Web search optional (Tavily API) can be disabled to reduce latency

### 📋 Known Limitations

- Rules created from AI are simplified:
  - Single condition (can be extended manually)
  - Single action (can be extended manually)
  - Multi-condition/AND-OR logic requires manual UI

- Web search (Tavily API):
  - Optional but recommended for compatibility queries
  - Requires Tavily API key in settings
  - Rate limits apply (5 requests/month on free tier)

- Chat history:
  - Stored only in browser localStorage (ephemeral)
  - Limited to last 20 messages to prevent bloat

## [1.3.1] - Earlier Release

(See GitHub releases for earlier changelog entries)

---

## Versioning

This project follows [Semantic Versioning](https://semver.org/):
- **MAJOR** version for breaking changes
- **MINOR** version for new features
- **PATCH** version for bug fixes
