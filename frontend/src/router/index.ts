import { createRouter, createWebHistory } from 'vue-router'
import type { RouterHistory } from 'vue-router'
import type { UserRole } from '../api/auth'
import AdminUsersView from '../views/AdminUsersView.vue'
import AdminCategoriesView from '../views/AdminCategoriesView.vue'
import AccountPasswordView from '../views/AccountPasswordView.vue'
import ForbiddenView from '../views/ForbiddenView.vue'
import HealthView from '../views/HealthView.vue'
import DashboardView from '../views/DashboardView.vue'
import AdminWorkloadView from '../views/AdminWorkloadView.vue'
import LoginView from '../views/LoginView.vue'
import NewTicketView from '../views/NewTicketView.vue'
import TicketDetailView from '../views/TicketDetailView.vue'
import TicketListView from '../views/TicketListView.vue'
import { installAuthGuards } from './guards'

declare module 'vue-router' {
  interface RouteMeta {
    public?: boolean
    role?: UserRole
  }
}

export function createAppRouter(history: RouterHistory = createWebHistory()) {
  const router = createRouter({
    history,
    routes: [
      { path: '/', name: 'home', component: DashboardView },
      { path: '/health', name: 'health', component: HealthView },
      {
        path: '/login',
        name: 'login',
        component: LoginView,
        meta: { public: true },
      },
      { path: '/forbidden', name: 'forbidden', component: ForbiddenView },
      {
        path: '/account/password',
        name: 'account-password',
        component: AccountPasswordView,
      },
      {
        path: '/admin/users',
        name: 'admin-users',
        component: AdminUsersView,
        meta: { role: 'admin' },
      },
      {
        path: '/admin/categories',
        name: 'admin-categories',
        component: AdminCategoriesView,
        meta: { role: 'admin' },
      },
      {
        path: '/admin/workload',
        name: 'admin-workload',
        component: AdminWorkloadView,
        meta: { role: 'admin' },
      },
      { path: '/tickets', name: 'tickets', component: TicketListView },
      { path: '/tickets/new', name: 'new-ticket', component: NewTicketView },
      {
        path: '/tickets/:id',
        name: 'ticket-detail',
        component: TicketDetailView,
      },
      { path: '/:pathMatch(.*)*', redirect: { name: 'home' } },
    ],
  })
  installAuthGuards(router)
  return router
}

export const router = createAppRouter()

export default router
