import json
import uuid
from pathlib import Path
from PIL import Image
from playwright.sync_api import sync_playwright, expect

root = Path(__file__).resolve().parents[1]
state = json.loads((root / "storage/cache/management-test.json").read_text())
base = "http://127.0.0.1:8765"
folder = root / "storage/cache"
for name, colour in [("portrait-test.png", "navy"), ("artwork-test.png", "teal"), ("retry-test.png", "maroon")]:
    Image.new("RGB", (80, 120), colour).save(folder / name)
passed = 0
def ok(label):
    global passed
    passed += 1
    print("PASS " + label, flush=True)

with sync_playwright() as p:
    browser = p.chromium.launch(headless=True)
    context = browser.new_context(viewport={"width": 390, "height": 844})
    page = context.new_page()
    errors = []
    page.on("pageerror", lambda error: errors.append(str(error)))
    page.goto(base + "/login")
    page.locator('[name="email"]').fill("test.facilitator@local.test")
    page.locator('[name="password"]').fill("Test-only-portrait-123")
    page.locator('button[type="submit"]').click()
    expect(page.get_by_role("link", name="Logout", exact=True)).to_be_visible()
    ok("real login works")
    page.goto(base + "/sessions/" + format(state["past"], "x") + "/upload")
    note_text = "Browser note saved alongside the photos " + uuid.uuid4().hex[:8]
    page.locator('[data-note-create] [name="text"]').fill(note_text)
    page.locator('[name="artworks[]"]').set_input_files(str(folder / "artwork-test.png"))
    page.locator('[name="pose_duration"]').fill("20 min")
    page.locator("#upload-btn").click()
    expect(page.locator("#upload-progress-text")).to_contain_text("Ready for the next pose.")
    expect(page.locator(".session-note").filter(has_text=note_text)).to_have_count(1)
    assert page.locator('[name="artworks[]"]').evaluate("(el) => el.files.length") == 0
    ok("pending note saves before photos and stays visible after upload")
    page.locator('[name="artworks[]"]').set_input_files(str(folder / "retry-test.png"))
    batch = page.locator('[name="batch_key"]').input_value()
    page.evaluate("""() => {
        window.OriginalXHR = window.XMLHttpRequest;
        window.XMLHttpRequest = class extends window.OriginalXHR {
            constructor() {
                super();
                this.addEventListener('load', () => {
                    if (this.status === 200) {
                        Object.defineProperty(this, 'responseText', {value: 'Simulated lost acknowledgement'});
                        Object.defineProperty(this, 'status', {value: 502});
                    }
                });
            }
        };
    }""")
    page.locator("#upload-btn").click()
    expect(page.locator("#upload-progress-text")).to_contain_text("unexpected response")
    assert page.locator('[name="artworks[]"]').evaluate("(el) => el.files.length") == 1
    assert page.locator('[name="batch_key"]').input_value() == batch
    page.evaluate("() => { window.XMLHttpRequest = window.OriginalXHR; }")
    page.locator("#upload-btn").click()
    expect(page.locator("#upload-progress-text")).to_contain_text("already saved")
    ok("lost upload acknowledgement retries the same pose without another image")
    # A partially saved batch resumes with its existing pose and deduplicates saved files.
    bad = folder / "partial-retry-test.jpg"
    bad.write_text("Deliberately invalid image")
    page.locator('[name="artworks[]"]').set_input_files([str(folder / "portrait-test.png"), str(bad)])
    partial_key = page.locator('[name="batch_key"]').input_value()
    page.locator("#upload-btn").click()
    expect(page.locator("#upload-progress-text")).to_contain_text("1 image(s) saved. Retry this batch")
    assert page.locator('[name="batch_key"]').input_value() == partial_key
    assert page.locator('[name="artworks[]"]').evaluate("(el) => el.files.length") == 2
    Image.new("RGB", (70, 90), "green").save(bad)
    page.locator('[name="artworks[]"]').set_input_files([str(folder / "portrait-test.png"), str(bad)])
    page.locator("#upload-btn").click()
    expect(page.locator("#upload-progress-text")).to_contain_text("1 image(s) saved, 1 already saved.")
    ok("partial upload retry keeps saved files and finishes the same batch")
    page.locator('[name="artworks[]"]').set_input_files(str(folder / "portrait-test.png"))
    break_text = "A sitter break, about five minutes " + uuid.uuid4().hex[:8]
    page.locator('[data-note-create] [name="text"]').fill(break_text)
    page.locator('[data-note-create] button[type="submit"]').click()
    expect(page.locator(".session-note").filter(has_text=break_text)).to_have_count(1)
    assert page.locator('[name="artworks[]"]').evaluate("(el) => el.files.length") == 1
    ok("saving a note leaves selected upload files intact")
    assert page.evaluate("document.documentElement.scrollWidth <= window.innerWidth")
    page.screenshot(path=str(folder / "management-upload-mobile.png"), full_page=True)
    ok("upload notes fit a phone viewport")
    page.goto(base + "/profile/edit")
    photo = page.locator("#profile-picture")
    photo.locator('[name="picture"]').set_input_files(str(folder / "portrait-test.png"))
    photo.locator('[name="non_nude"]').check()
    photo.locator('form[enctype="multipart/form-data"] button[type="submit"]').click()
    expect(page.locator("#profile-picture img")).to_be_visible()
    first_url = page.locator("#profile-picture img").get_attribute("src")
    assert context.request.get(base + first_url).status == 200
    page.goto(base + "/profile/" + format(state["facilitator"], "x"))
    expect(page.locator(".profile-header img")).to_be_visible()
    ok("optional profile photo uploads and appears on the public profile")
    page.goto(base + "/profile/edit")
    photo = page.locator("#profile-picture")
    photo.locator('[name="picture"]').set_input_files(str(folder / "retry-test.png"))
    photo.locator('[name="non_nude"]').check()
    photo.locator('form[enctype="multipart/form-data"] button[type="submit"]').click()
    new_url = page.locator("#profile-picture img").get_attribute("src")
    assert new_url != first_url
    assert context.request.get(base + first_url).status == 404
    ok("replacing a profile photo changes its URL and removes the previous file")
    page.once("dialog", lambda dialog: dialog.accept())
    page.get_by_role("button", name="Remove photo", exact=True).click()
    expect(page.locator("#profile-picture img")).to_have_count(0)
    assert context.request.get(base + new_url).status == 404
    ok("profile photo removal removes the file")
    page.goto(base + "/manage/users")
    page.locator('[name="q"]').fill("Unbooked Stub")
    page.get_by_role("button", name="Search", exact=True).click()
    page.get_by_role("link", name="Unbooked Stub", exact=True).click()
    expect(page.locator("body")).to_contain_text("No email recorded")
    assert page.locator('[href^="mailto:"]').count() == 0
    ok("People directory finds a stub without any booking")
    page.goto(base + "/manage/sessions/" + format(state["past"], "x"))
    page.screenshot(path=str(folder / "management-session-mobile.png"), full_page=True)
    assert page.evaluate("document.documentElement.scrollWidth <= window.innerWidth")
    ok("management view fits a phone viewport")
    assert not errors, errors
    ok("no browser JavaScript errors")
    browser.close()
print(f"{passed} browser checks passed.", flush=True)
