# User Guide

This guide explains how to use the tldraw drawing editor within Nextcloud.

> **Prerequisite:** The tldraw app and its Collab Server backend must be set up by your Nextcloud administrator before you can use it. If you see a connection error when opening a drawing, contact your admin.

---

## Creating a New Drawing

1. Navigate to the **Files** app in Nextcloud.
2. Click the **+ New** button in the top bar.
3. Select **New tldraw drawing**.
4. Enter a name ending in `.tldr` (e.g. `Brainstorm.tldr`) and press Enter.

The file is created and automatically opened in the editor.

---

## The Editor

The editor is the standard [tldraw](https://tldraw.dev) interface:

- **Toolbar (bottom):** Draw, Select, Hand, Eraser, Shapes, Arrows, Text, and more.
- **Menu (top left):** File options, preferences, and view settings.
- **Zoom:** Mouse wheel, or pinch on a trackpad.
- **Pan:** Hold Space and drag, or use the Hand tool.

Your changes are saved back to Nextcloud automatically every 30 seconds and when you close the file.

---

## Real-Time Collaboration

Collaboration happens automatically when multiple users have the same file open.

1. **Share the file:** Use Nextcloud's standard sharing sidebar to share the `.tldr` file with other users or groups.
2. **Open simultaneously:** When another user opens the file, you will see their cursor and changes appear in real-time.
3. **Permissions follow Nextcloud:**
   - Users with **edit** permission can draw and modify the canvas.
   - Users with **view/read-only** permission can see the drawing but cannot make changes.

---

## Exporting

To export your drawing:

1. Click the **Menu** button (top left hamburger icon).
2. Select **Export**.
3. Choose a format:
   - **SVG** — scalable vector graphic, best for print or further editing.
   - **PNG** — raster image, good for sharing or embedding.
   - **JSON** — the raw tldraw document format, useful for backups or migration.

> Exports are downloaded to your computer. They are not saved back to Nextcloud.

---

## Inserting Images

You can embed images from your computer directly onto the canvas:

- **Drag and drop:** Drag an image file from your desktop onto the browser window.
- **Copy and paste:** Copy an image to your clipboard and press `Ctrl+V` (or `Cmd+V`).

Inserted images are uploaded to your Nextcloud storage and embedded in the drawing, so collaborators see them too.

**Supported formats:** JPEG, PNG, GIF, WebP.

> SVG files cannot be uploaded as embedded images for security reasons. You can still *export* your drawing as an SVG from the menu.
