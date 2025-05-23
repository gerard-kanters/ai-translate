You are working in a Windows development environment with Laragon (Apache, MySQL). The production environment is Linux. The website is netcare.nl with the ai-translate plugin. Log files are located in d:\laragon\log\netcare.

The theme we are using is NetCare in d:\laragon\wp-content\themes

We are working on the ai-translate plugin. Only search further in the codebase if specifically requested. Otherwise, the development process becomes too slow.

Plugin description:
This #codebase ai-translate is a Wordpress plugin that can translate sites into various languages. The admin can choose which languages are visible to the user via a flag at the bottom of the page with a popup showing the selected languages. Furthermore, there are also languages that are not visible but are detected via the URL or cookie. The administrator can also choose these. These languages get their own URL /langcode/

🎯 Rules for communication by the AI with the user
- Always answer in Dutch.
- Do not continuously ask for confirmation. If a problem is described, it must be solved.
- If you answer in "ASK" mode. Provide the entire function. In agent mode, you can implement changes.
- Include line numbers and file names in the answer. Not in edit or agent mode.
- The production environment is Linux, the development environment is Windows. So provide Linux commands and not Windows commands. This environment is the development environment.
- The AI should not give instructions to the user to check code. The AI must do that itself.
- Do not ask the user to check things in code. Do it yourself and provide a thorough analysis or advice.
- In agent and edit mode, the AI must do the analyses and code changes itself.

Technologies used:
- php
- wordpress #fetch https://developer.wordpress.org/plugins/
- javascript
- css
- The environment uses Windows Powershell.
- Use MCP tools and not shell commands.

Plugin structure: ai-translate

Main plugin file /

ai-translate.php: Contains activation/deactivation hooks, initialization, and registration of filters/actions.

Includes map

/includes/admin-page.php: Admin settings page, displays fields, and saves options.

/includes/class-ai-translate.php: All translation core: API calls, caching, translation logic.

Assets map

/assets/flags/ Flags per language (PNG).

/assets/ JavaScript and CSS for the language switcher.

🎯 Rules for code changes
Lint checking is crucial

- The plugin should not contains unescaped output
- The plugin should not accepts unsanitized data
- The plugin should not processes form data without a nonce
- First, run Intelephense, see and fix all errors/warnings before you start.
- Do not program around problems if it is a bug. Find the bug instead of fixing it.
- DocBlocks and Comments in English.
- Read first, then write.
- Avoid regex and preg_replace where possible. Use DOM parser and php/wordpress functions for HTML handling.
- Do not ask questions if the answer is in these instructions.
- Do not add comments like NEW or Existing code. That is just polluting.
- Do not include local references. Always generic solutions.
- Understand how the existing code works.
- Prevent duplicate code by first searching for existing functions.
- Minimum number of API calls.
- Cache translations in transient or own option.
- No new calls for already translated pages.
- Follow WordPress conventions.
- Use wp_enqueue_script(), register_setting(), add_action(), etc.
- Adhere to Coding Standards.
- Safe, minimal changes.
- Do not break anything outside the intended scope.
- If there are multiple ways: choose the safest one.
- Maintain structure and documentation.
- No debug over explanatory texts in the function itself, unless really necessary to understand why.
- Keep names and folders consistent.
- Delete = really delete, not delete as a comment.
- Robust, reusable functions.
- Generalize where possible.
- No recursive calls that lead to loops.
- Do not create new files without an order. Work within the 3 existing PHP files unless otherwise requested.
- Final check for linter errors.

After all your changes: run Intelephense again: 0 errors, 0 warnings. 🚨

⚠️ Pitfalls to avoid
→ Do not translate shortcodes (that is the default).
→ Check that you do not break anything called via [shortcode].
→ Accidentally translating scripts/CSS.
→ Filter your translatable strings; skipped assets remain untouched.
→ Default language via API.
→ Set a fallback, but never unintentionally use the API to retrieve the default language.
→ Functions in admin-page.php vs. class-ai-translate.php
→ Save settings AND also use them in the translate class, not just register them.