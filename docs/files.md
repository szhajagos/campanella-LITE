# Files

Campanella Lite keeps images, documents, sounds and videos in one file repository. Admins add files in the dashboard or on the command line; the AI module will add them through the same service later. After that, everything is done with commands.

## How a file comes in

1. **Upload.** Drop files on the command box (or use *Attach files*) and press *Run*; on the command line, `bin/campanella --upload=<path>`.
2. **Check.** The type is detected from the content, not from the name (see [accepted types](#accepted-types)), and the size limit is checked. Images are decoded once with GD, if the server has it.
3. **Store.** The file is copied to `project/files/store/{xx}/{random}.{extension}`, its manifest is written to `project/files/manifest/{id}.yaml`, and it gets the next id.
4. **Response.** The response shows the id, the name, the type, the size and a ready reference: `![](file:12)` for images, `[Price list.pdf](file:13)` for other files.

Uploading changes nothing on the website, because nothing uses the file yet. It is written to the command log (`uploaded`) but is not a batch, so there is nothing to undo. Every later step is a command: logged, checked and undoable.

There is no placeholder for "the file I am uploading now": upload first, then use the id the response gives.

## Ids and names

- The **id** is a positive number, given out in order and never reused, not even after a file has been purged (`project/files/next-id` remembers the next one).
- The **file name** is what people see. It comes from the uploaded file (without folders and control characters, with the extension of the detected type: `Photo.JPEG` becomes `Photo.jpg`) and can be changed with `file rename`. The extension cannot change.
- The **stored name** is random and has nothing to do with the id or the file name, so the stored file can move without touching any reference.

## Manifest

```yaml
# project/files/manifest/12.yaml
id: 12
filename: Conference hall.jpg
stored_filename: 3f/9c1e0b5a7d2f4e6c8b1a3d5f7e9c0b2a.jpg
mime_type: image/jpeg
extension: jpg
size: 482113
width: 1600
height: 1067
description:
  en: 'The conference hall'
  hu: 'Az előadóterem'
uploaded_at: '2026-11-08T19:42:10+01:00'
uploaded_by: admin
archived_at: '2026-11-09T08:00:00+01:00'   # only for archived files
```

`width` and `height` are present for images. `description` is per language; for images it is also the alt text. `uploaded_by` is the logged-in admin (from the session, never from a command) or `cli`.

## Accepted types

| Kind | Extensions | Limit |
| --- | --- | --- |
| image | jpg, png, webp, gif | 10 MB, at most 10000 px per side and 50 megapixels |
| document | pdf, docx, xlsx, pptx | 10 MB |
| audio | wav, aiff, flac, mp3, opus, ogg | 10 MB |
| video | mp4, webm | 100 MB |

Never accepted, because they can carry scripts or macros: HTML, SVG, PHP and other code; macro-enabled and legacy Office formats (docm, xlsm, pptm, doc, xls, ppt); QuickTime, Matroska and HEIF/AVIF containers.

Extra checks:

- **Office documents** are ZIP packages; they must declare a Word, Excel or PowerPoint main part, must not declare macros, and must not contain `vbaProject.bin` or ActiveX controls. This needs PHP's zip extension; without it Office files are refused.
- **PDF files** are refused if they contain JavaScript, launch actions, embedded files, rich media or XFA forms. The check reads the raw file (with `#xx` name escapes decoded); content hidden inside compressed object streams would need a full PDF parser.
- **Images** must be decodable; with GD they are decoded once (unless that would exceed PHP's memory limit).

The server's own upload limits (`upload_max_filesize`, `post_max_size`) can be lower than these; the response says so when a file is cut off.

## Commands

| Command | Description |
| --- | --- |
| `list files [--type=…] [--name=…] [--by=…] [--since=YYYY-MM-DD] [--search=…] [--archived]` | Lists files; every option narrows the list. `--search` looks in names and descriptions. |
| `get file.12`, `get file.12.description.hu` | Shows the manifest or one field. |
| `set file.12.description.hu "…"`, `set file.12.filename "…"` | Changes the description or the file name. |
| `file describe 12 [--lang=hu] "…"` | Sets the description in a language; `""` removes it. |
| `file rename 12 "…"` | Changes the file name; the extension is added if missing. |
| `file delete 12 [--force]` | Archives the file. If content refers to it, only with `--force`. |
| `file restore 12` | Brings back an archived file. |
| `file purge [12]` | Removes archived files (or one) for good, after the batch. Files that content still uses are kept. Cannot be undone. |

## References in content

Content refers to files by id in Markdown links and images: `![](file:12)`, `[Price list](file:13)`. The validator reports a reference to a file that does not exist (`content.unknown_file`); archived files are still allowed, because archiving is reversible. How references are shown on the website (image sizes, WebP, players, download links) is part of milestone 8.

## Deleting and undo

`file delete` only sets `archived_at` in the manifest, so `undo` and `file restore` work without keeping copies of large files. `file purge` deletes the manifest and the stored file; undoing an earlier batch that touched a purged file's manifest is then refused, because that file has changed since.
