import type { Page } from '@playwright/test';
import { expect, test } from './fixtures';
import {
    BABBEL_ADMIN,
    babbelStoryStatus,
    clickToSavePost,
    controlStory,
    currentPostID,
    deleteBabbelStory,
    disposeBabbelContext,
    getBabbelStory,
    savePost,
    setBabbelEnabled,
} from './utils';

const runID = Date.now().toString();
const originalTitle = `Browser E2E bericht ${runID}`;
const originalContent =
    'Dit artikel wordt volledig via de WordPress-editor gepubliceerd en naar Babbel gestuurd.';
// Keep in sync with the AI Client MU plugin and tests/e2e/suite.php.
const generatedText = 'Deterministische E2E-radiospreektekst.';

test.describe
    .serial('WordPress redacteursflow', () => {
        let postID = 0;
        let storyID = '';

        test.afterAll(async () => {
            await disposeBabbelContext();
        });

        test('beheerder configureert en test de integratie via Instellingen', async ({
            page,
        }) => {
            await page.goto(
                '/wp-admin/options-general.php?page=zw-knabbel-wp-settings',
            );

            const settings = {
                api_base_url: 'http://babbel:8080/api/v1',
                api_username: BABBEL_ADMIN.username,
                api_password: BABBEL_ADMIN.password,
                start_days_offset: '1',
                end_days_offset: '2',
            };
            for (const [key, value] of Object.entries(settings)) {
                await page
                    .locator(`[name="knabbel_settings[${key}]"]`)
                    .fill(value);
            }
            await page.getByLabel('AI Model').selectOption('openai/gpt-4.1');

            const debugMode = page.locator(
                '[name="knabbel_settings[debug_mode]"]',
            );
            if (!(await debugMode.isChecked())) {
                await page
                    .getByText('Enable Debug Mode', { exact: true })
                    .click();
            }

            await Promise.all([
                page.waitForURL(
                    /\/wp-admin\/options-general\.php\?page=zw-knabbel-wp-settings/,
                ),
                page.locator('button[form="knabbel-settings-form"]').click(),
            ]);
            await expect(
                page.locator('[name="knabbel_settings[api_base_url]"]'),
            ).toHaveValue('http://babbel:8080/api/v1');
            await expect(debugMode).toBeChecked();
            await expect(
                page.getByRole('link', { name: 'Manage AI providers' }),
            ).toHaveAttribute('href', /options-connectors\.php$/);
            await expect(page.getByLabel('AI Model')).toHaveValue(
                'openai/gpt-4.1',
            );
            await expect(
                page.getByLabel('Speech Text Generation Prompt'),
            ).toBeVisible();
            await expect(
                page.locator('[name="knabbel_settings[few_shot_count]"]'),
            ).toHaveCount(0);

            await page.locator('#test-babbel-api').click();
            await expect(page.locator('#api-test-result')).toHaveClass(
                /success/,
            );
            await expect(page.locator('#api-test-result')).toContainText(
                /admin/i,
            );
        });

        test('redacteur publiceert een bericht en laat de wachtrij het naar Babbel sturen', async ({
            page,
        }) => {
            await page.goto('/wp-admin/post-new.php');
            await page.locator('#title').fill(originalTitle);
            await page.locator('#content-html').click();
            await page.locator('#content').fill(originalContent);
            await setBabbelEnabled(page, true);

            await savePost(page);
            postID = currentPostID(page);
            await expect(
                page.locator('.knabbel-status-badge.scheduled'),
            ).toBeVisible();

            const result = await controlStory(page, postID, 'run');
            expect(result.state.story_id).toBeTruthy();
            storyID = result.state.story_id || '';

            await page.reload();
            await expect(
                page.locator('.knabbel-status-badge.sent'),
            ).toBeVisible();
            await expect(page.locator('#knabbel_send_to_babbel')).toBeChecked();

            const story = await getBabbelStory(storyID);
            expect(story.title).toBe(originalTitle);
            expect(story.text).toBe(generatedText);
            expect(story.metadata?.wordpress_id).toBe(postID);
        });

        test('redacteur slaat op en synchroniseert opnieuw na een Babbel-fout', async ({
            page,
        }) => {
            await page.goto(`/wp-admin/post.php?post=${postID}&action=edit`);
            await failOneTitleSave(page, postID, `${originalTitle} mislukt`);
            expect((await getBabbelStory(storyID)).title).toBe(originalTitle);

            // An unsaved edit must survive the retry button.
            const retriedTitle = `${originalTitle} opnieuw`;
            await page.locator('#title').fill(retriedTitle);
            await clickToSavePost(page, '.knabbel-sync-retry');

            await expect(page.locator('#title')).toHaveValue(retriedTitle);
            await expect(page.locator('#original_post_status')).toHaveValue(
                'publish',
            );
            await expect(
                page.locator('.misc-pub-knabbel .knabbel-sync-warning'),
            ).toHaveCount(0);
            await expect(
                page.locator('.knabbel-status-badge.sent'),
            ).toBeVisible();
            expect((await getBabbelStory(storyID)).title).toBe(retriedTitle);

            const windowStart = (
                await getBabbelStory(storyID)
            ).start_date?.slice(0, 10);
            // A date-only edit must survive the retry button. Moving a published post to an earlier
            // day never pulls its window before the moment the story went live.
            await failOneTitleSave(page, postID, `${retriedTitle} mislukt`);
            await page.locator('a.edit-timestamp').click();
            const date = new Date(
                Date.UTC(
                    Number(await page.locator('#aa').inputValue()),
                    Number(await page.locator('#mm').inputValue()) - 1,
                    Number(await page.locator('#jj').inputValue()) - 3,
                ),
            );
            await page.locator('#aa').fill(String(date.getUTCFullYear()));
            await page
                .locator('#mm')
                .selectOption(String(date.getUTCMonth() + 1).padStart(2, '0'));
            await page.locator('#jj').fill(String(date.getUTCDate()));
            await page.locator('a.save-timestamp').click();
            await clickToSavePost(page, '.knabbel-sync-retry');

            await expect(page.locator('#original_post_status')).toHaveValue(
                'publish',
            );
            await expect(page.locator('#hidden_jj')).toHaveValue(
                String(date.getUTCDate()).padStart(2, '0'),
            );
            const story = await getBabbelStory(storyID);
            expect(story.start_date?.slice(0, 10)).toBe(windowStart);
            await expect(
                page.locator('.misc-pub-knabbel .knabbel-sync-warning'),
            ).toHaveCount(0);

            // A checkbox edit must survive the retry button.
            await failOneTitleSave(
                page,
                postID,
                `${retriedTitle} weer mislukt`,
            );
            await setBabbelEnabled(page, false);
            await clickToSavePost(page, '.knabbel-sync-retry');
            await expect(
                page.locator('#knabbel_send_to_babbel'),
            ).not.toBeChecked();
            await expect(
                page.locator('.knabbel-status-badge.deleted'),
            ).toBeVisible();
            expect(await babbelStoryStatus(storyID)).toBe(404);

            // Leave Radio News enabled, so the next test disables it itself.
            await setBabbelEnabled(page, true);
            await savePost(page);
            await expect(
                page.locator('.knabbel-status-badge.sent'),
            ).toBeVisible();
        });

        test('redacteur herstelt een verhaal dat buiten WordPress in Babbel is verwijderd', async ({
            page,
        }) => {
            await page.goto(`/wp-admin/post.php?post=${postID}&action=edit`);
            await deleteBabbelStory(storyID);
            await page
                .locator('#title')
                .fill(`${originalTitle} na verwijdering`);
            await savePost(page);
            await expect(
                page.locator('.knabbel-status-badge.deleted_in_babbel'),
            ).toBeVisible();
            expect(await babbelStoryStatus(storyID)).toBe(404);

            const restoredTitle = `${originalTitle} hersteld`;
            await page.locator('#title').fill(restoredTitle);
            await clickToSavePost(page, 'button[name="knabbel_restore_story"]');

            await expect(page.locator('#title')).toHaveValue(restoredTitle);
            await expect(
                page.locator('.knabbel-status-badge.sent'),
            ).toBeVisible();
            await expect(
                page.locator('.misc-pub-knabbel .knabbel-sync-warning'),
            ).toHaveCount(0);
            expect((await getBabbelStory(storyID)).title).toBe(restoredTitle);
        });

        test('redacteur schakelt Babbel uit en herstelt daarna hetzelfde verhaal', async ({
            page,
        }) => {
            await page.goto(`/wp-admin/post.php?post=${postID}&action=edit`);
            await setBabbelEnabled(page, false);
            await savePost(page);
            await expect(
                page.locator('.knabbel-status-badge.deleted'),
            ).toBeVisible();

            expect(await babbelStoryStatus(storyID)).toBe(404);

            await page.reload();
            await setBabbelEnabled(page, true);
            await savePost(page);
            await expect(
                page.locator('.knabbel-status-badge.sent'),
            ).toBeVisible();

            await getBabbelStory(storyID);
        });
    });

/**
 * Save a title edit while Babbel writes fail, so the sync warning appears.
 */
async function failOneTitleSave(
    page: Page,
    postID: number,
    title: string,
): Promise<void> {
    await controlStory(page, postID, 'fail_writes');
    try {
        await page.locator('#title').fill(title);
        await savePost(page);
    } finally {
        await controlStory(page, postID, 'restore_writes');
    }
    await expect(
        page.locator('.misc-pub-knabbel .knabbel-sync-warning'),
    ).toBeVisible();
}
