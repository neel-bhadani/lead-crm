import { h } from 'vue'
export const Link = (props, { slots }) => h('a', { href: props.href }, slots.default?.())
export const router = { post: () => {} }
export const usePage = () => ({ props: { auth: { user: { name: 'Test User', role: 'admin' } } } })
export const Head = () => null
