import {
    type APIRequestContext,
    type APIResponse,
    expect,
    type Page,
    request,
} from '@playwright/test';

export interface StoryState {
    status?: string;
    story_id?: string;
}

export interface ControlResult {
    pending: number;
    state: StoryState;
}

export interface BabbelStory {
    title: string;
    start_date?: string;
    text: string;
    status?: string;
    metadata?: {
        wordpress_id?: number;
    };
}

// Keep these credentials in sync with tests/e2e/run.sh and suite.php.
export const WP_ADMIN = { username: 'admin', password: 'e2e-admin-password' };
export const BABBEL_ADMIN = { username: 'admin', password: 'admin' };

const babbelURL = process.env.PLAYWRIGHT_BABBEL_URL;
let babbelContextPromise: Promise<APIRequestContext> | undefined;

/**
 * Assert a response succeeded, using its body as the failure message.
 *
 * Redirect responses have no readable body, so fall back to the label.
 */
async function expectResponseOk(
    response: { status(): number; text(): Promise<string> },
    label: string,
): Promise<void> {
    const status = response.status();
    const detail =
        status < 400 ? label : await response.text().catch(() => label);
    expect(status, detail).toBeLessThan(400);
}

export async function login(page: Page): Promise<void> {
    await page.goto('/wp-login.php');
    const loginResponse = page.waitForResponse(
        (response) =>
            response.request().method() === 'POST' &&
            new URL(response.url()).pathname === '/wp-login.php',
    );
    const [response] = await Promise.all([
        loginResponse,
        page
            .locator('#loginform')
            .evaluate((form: HTMLFormElement, credentials) => {
                const username = form.elements.namedItem('log');
                const password = form.elements.namedItem('pwd');
                if (
                    !(username instanceof HTMLInputElement) ||
                    !(password instanceof HTMLInputElement)
                ) {
                    throw new Error('The login fields are unavailable.');
                }

                username.value = credentials.username;
                password.value = credentials.password;
                form.submit();
            }, WP_ADMIN),
    ]);
    await expectResponseOk(response, 'Login failed.');
    await expect(page).toHaveURL(/\/wp-admin\//);
}

export async function savePost(page: Page): Promise<void> {
    await submitPost(page, () =>
        page.locator('#publish').evaluate((button: HTMLInputElement) => {
            if (!button.form) {
                throw new Error(
                    'The publish button is not associated with a form.',
                );
            }
            button.form.requestSubmit(button);
        }),
    );
}

/**
 * Click a submit button of the post form the way an editor does.
 */
export async function clickToSavePost(
    page: Page,
    button: string,
): Promise<void> {
    await submitPost(page, () => page.locator(button).click());
}

async function submitPost(
    page: Page,
    submit: () => Promise<unknown>,
): Promise<void> {
    const postResponse = page.waitForResponse(
        (response) =>
            response.request().method() === 'POST' &&
            new URL(response.url()).pathname === '/wp-admin/post.php',
    );
    const navigation = page.waitForEvent(
        'framenavigated',
        (frame) => frame === page.mainFrame(),
    );

    const [response] = await Promise.all([postResponse, navigation, submit()]);
    await expectResponseOk(response, 'Post save failed.');
    await expect(page).toHaveURL(/\/wp-admin\/post\.php\?post=\d+&action=edit/);
    await expect(page.locator('#publish')).toBeVisible();
}

export function currentPostID(page: Page): number {
    const postID = Number(new URL(page.url()).searchParams.get('post'));
    expect(postID).toBeGreaterThan(0);

    return postID;
}

export async function setBabbelEnabled(
    page: Page,
    enabled: boolean,
): Promise<void> {
    const checkbox = page.locator('#knabbel_send_to_babbel');
    const toggle = page.locator('.misc-pub-knabbel .knabbel-submitbox-toggle');

    await expect(toggle).toBeVisible();
    await expect(checkbox).toBeEnabled();

    if ((await checkbox.isChecked()) !== enabled) {
        await toggle.click();
    }
    await expect(checkbox).toBeChecked({ checked: enabled });
    await expect(
        toggle.locator(
            enabled
                ? '.knabbel-submitbox-toggle-enabled'
                : '.knabbel-submitbox-toggle-disabled',
        ),
    ).toBeVisible();
}

export async function controlStory(
    page: Page,
    postID: number,
    operation: 'inspect' | 'run' | 'fail_writes' | 'restore_writes',
): Promise<ControlResult> {
    const nonce = await page.locator('#knabbel_nonce').inputValue();
    const response = await page.request.post('/wp-admin/admin-ajax.php', {
        form: {
            action: 'knabbel_e2e_control',
            nonce,
            operation,
            post_id: String(postID),
        },
    });

    expect(response.status(), await response.text()).toBe(200);
    const body = (await response.json()) as {
        success: boolean;
        data: ControlResult;
    };
    expect(body.success).toBe(true);

    return body.data;
}

/**
 * Read a story that must exist. Fails the test when it does not.
 */
export async function getBabbelStory(storyID: string): Promise<BabbelStory> {
    const response = await babbelRequest(
        `/stories/${encodeURIComponent(storyID)}`,
    );
    expect(response.status(), await response.text()).toBe(200);

    return (await response.json()) as BabbelStory;
}

/**
 * Delete a story directly in Babbel, as an editor outside WordPress would.
 */
export async function deleteBabbelStory(storyID: string): Promise<void> {
    const response = await babbelRequest(
        `/stories/${encodeURIComponent(storyID)}`,
        { method: 'DELETE' },
    );
    expect(response.status(), await response.text()).toBe(204);
}

/**
 * Read only the status code, for assertions about a story's absence.
 */
export async function babbelStoryStatus(storyID: string): Promise<number> {
    const response = await babbelRequest(
        `/stories/${encodeURIComponent(storyID)}`,
    );

    return response.status();
}

export async function disposeBabbelContext(): Promise<void> {
    const contextPromise = babbelContextPromise;
    babbelContextPromise = undefined;
    const context = await contextPromise?.catch(() => undefined);
    await context?.dispose();
}

async function babbelRequest(
    path: string,
    options?: Parameters<APIRequestContext['fetch']>[1],
): Promise<APIResponse> {
    if (!babbelURL) {
        throw new Error('PLAYWRIGHT_BABBEL_URL is required.');
    }

    // Memoize the in-flight promise, not the resolved context: a single worker does
    // not stop two concurrent callers from both entering this branch, which would
    // leak a context and let one request run before the shared login completed.
    if (!babbelContextPromise) {
        babbelContextPromise = (async () => {
            const context = await request.newContext();
            try {
                const session = await context.post(`${babbelURL}/sessions`, {
                    data: BABBEL_ADMIN,
                });
                expect(session.status(), await session.text()).toBe(201);

                return context;
            } catch (error) {
                await context.dispose();
                throw error;
            }
        })();
    }

    // Reset on failure so a later call retries the login instead of reusing a
    // rejected promise, but only if nothing else replaced it in the meantime.
    const contextPromise = babbelContextPromise;
    let babbelContext: APIRequestContext;
    try {
        babbelContext = await contextPromise;
    } catch (error) {
        if (babbelContextPromise === contextPromise) {
            babbelContextPromise = undefined;
        }
        throw error;
    }

    return babbelContext.fetch(`${babbelURL}${path}`, options);
}
