import { test, expect } from '@playwright/test'

const spisok = (page) => page.locator('#nt-list .nt-text')

async function dobavit(page, ...teksty) {
  await page.locator('.nt-new__btn').click()
  for (const t of teksty) {
    await page.locator('#nt-new-inp').fill(t)
    await page.locator('#nt-new-inp').press('Enter')
  }
  await page.locator('#nt-new-inp').press('Escape')
}

test.beforeEach(async ({ page }) => {
  await page.goto('/')
  await page.evaluate(() => localStorage.clear())
  await page.reload()
})

test('T1 добавить 3 задачи и обновить', async ({ page }) => {
  await dobavit(page, 'Один', 'Два', 'Три')
  await page.reload()
  await expect(spisok(page)).toHaveText(['Один', 'Два', 'Три'])
})

test('T2 отметка сохраняется', async ({ page }) => {
  await dobavit(page, 'Задача')
  await page.locator('#nt-list .nt-check').first().click()
  await page.reload()
  await expect(page.locator('#nt-done-list .nt-text')).toHaveText(['Задача'])
  await page.locator('#nt-done-list .nt-check').first().click()
  await page.reload()
  await expect(spisok(page)).toHaveText(['Задача'])
})

test('T3 и T4 удаление и отмена', async ({ page }) => {
  await dobavit(page, 'А', 'Б', 'В')
  const row = page.locator('#nt-list .nt-row').nth(1)
  await row.hover()
  await row.locator('.nt-row__del').click()
  await page.locator('.nt-toast__btn').click()
  await expect(spisok(page)).toHaveText(['А', 'Б', 'В'])
  await row.hover()
  await row.locator('.nt-row__del').click()
  await page.waitForTimeout(400)
  await page.reload()
  await expect(spisok(page)).toHaveText(['А', 'В'])
})

test('T5 пустой ввод, 501 символ и html', async ({ page }) => {
  await dobavit(page, '', '    ', 'я'.repeat(501), '<script>alert(1)</script>')
  const t = await spisok(page).allTextContents()
  expect(t).toHaveLength(2)
  expect(t[0]).toHaveLength(500)
  expect(t[1]).toBe('<script>alert(1)</script>')
})
