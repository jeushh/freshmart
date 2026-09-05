const icon = (...paths) => paths

export const navigationGroups = [
  {
    label: 'Overview',
    items: [
      {
        to: '/',
        label: 'Dashboard',
        permission: '',
        hideOnLanding: ['finance'],
        icon: icon(
          'M4 13h6V4H4v9Zm10 7h6V11h-6v9ZM4 20h6v-3H4v3Zm10-13h6V4h-6v3Z'
        )
      },
      {
        to: '/self-service',
        label: 'Self-Service',
        permission: 'employee.self',
        icon: icon(
          'M12 12a4 4 0 1 0 0-8 4 4 0 0 0 0 8Z',
          'M4.5 20a7.5 7.5 0 0 1 15 0'
        )
      }
    ]
  },
  {
    label: 'Point of Sale',
    accent: 'pos',
    items: [
      {
        to: '/pos',
        label: 'Point of Sale',
        permission: 'pos.access',
        icon: icon(
          'M3 5h2l1.5 9h10l2-6H6',
          'M9 19a1 1 0 1 1-2 0 1 1 0 0 1 2 0Zm9 0a1 1 0 1 1-2 0 1 1 0 0 1 2 0Z'
        )
      }
    ]
  },
  {
    label: 'Inventory',
    accent: 'inventory',
    items: [
      {
        to: '/inventory',
        label: 'Products',
        permission: 'inventory.manage',
        icon: icon(
          'M4 7.5 12 4l8 3.5-8 3.5-8-3.5Z',
          'M4 7.5V16l8 4 8-4V7.5M12 11v9'
        )
      },
      {
        to: '/inventory/low-stock',
        label: 'Low Stock',
        permission: 'inventory.manage',
        icon: icon(
          'M12 4 3.5 19h17L12 4Z',
          'M12 9v4m0 3h.01'
        )
      },
      {
        to: '/inventory/movements',
        label: 'Inventory Movements',
        permission: 'inventory.manage',
        icon: icon(
          'M5 8h14m-4-4 4 4-4 4M19 16H5m4 4-4-4 4-4'
        )
      },
      {
        to: '/restock-requests',
        label: 'Restock Requests',
        permission: 'restock.request|restock.approve',
        icon: icon(
          'M6 4h12v16H6z',
          'M9 8h6m-6 4h6m-6 4h4'
        )
      },
      {
        to: '/purchase-orders',
        label: 'Purchase Orders',
        permission: 'procurement.purchase_orders.view',
        icon: icon(
          'M5 3h14v18H5z',
          'M8 7h8m-8 4h8m-8 4h5'
        )
      },
      {
        to: '/stock-receiving',
        label: 'Stock Receiving',
        permission: 'procurement.stock.receive',
        icon: icon(
          'M4 8h11v10H4zM15 11h3l2 3v4h-5z',
          'M8 20a2 2 0 1 0 0-4 2 2 0 0 0 0 4Zm9 0a2 2 0 1 0 0-4 2 2 0 0 0 0 4Z'
        )
      }
    ]
  },
  {
    label: 'Human Resources',
    accent: 'hr',
    items: [
      {
        to: '/employees',
        label: 'Employees',
        permission: 'hr.employees.view',
        icon: icon(
          'M9 11a3.5 3.5 0 1 0 0-7 3.5 3.5 0 0 0 0 7Zm-6 9a6 6 0 0 1 12 0',
          'M16 8a3 3 0 0 1 0 6m1 1a5 5 0 0 1 4 5'
        )
      },
      {
        to: '/attendance',
        label: 'Attendance',
        permission: 'hr.attendance.view',
        icon: icon(
          'M5 5h14v15H5zM8 3v4m8-4v4M5 9h14',
          'm9 14 2 2 4-5'
        )
      },
      {
        to: '/hr-requests',
        label: 'HR Requests',
        permission: 'hr.requests.view',
        icon: icon(
          'M6 4h9l3 3v13H6zM15 4v4h4',
          'M9 12h6m-6 4h4'
        )
      },
      {
        to: '/payroll',
        label: 'Payroll',
        permission: 'payroll.manage',
        icon: icon(
          'M4 6h16v12H4z',
          'M8 14c1.5 1 6 1 6-1.5S9.5 11 9.5 9.5 14 8 16 9M7 9h.01M17 15h.01'
        )
      }
    ]
  },
  {
    label: 'Finance',
    accent: 'finance',
    items: [
      {
        to: '/finance',
        label: 'Finance Overview',
        permission: 'finance.requests.view|finance.manage',
        icon: icon(
          'M4 20V10m5 10V4m6 16v-7m5 7V7'
        )
      },
      {
        to: '/finance/requests',
        label: 'Finance Requests',
        permission: 'finance.requests.view',
        icon: icon('M6 4h9l3 3v13H6zM15 4v4h4', 'M9 13h6m-6 4h4')
      },
      {
        to: '/finance/transactions',
        label: 'Transactions',
        permission: 'finance.manage',
        icon: icon('M5 19V9m5 10V5m5 14v-6m5 6V3')
      },
      {
        to: '/finance/supplier-invoices',
        label: 'Supplier Invoices',
        permission: 'finance.manage',
        icon: icon('M6 3h9l3 3v15H6zM15 3v4h4', 'M9 12h6m-6 4h6')
      },
      {
        to: '/finance/accounts-payable',
        label: 'Accounts Payable',
        permission: 'finance.manage',
        icon: icon('M4 6h16v12H4z', 'M8 14h8M8 10h4')
      }
    ]
  },
  {
    label: 'Reports',
    items: [
      {
        to: '/reports',
        label: 'Reports',
        permission: 'reports.sales.view|reports.inventory.view|reports.procurement.view|reports.hr.view|reports.payroll.view|reports.finance.view',
        icon: icon(
          'M5 20V10m5 10V4m5 16v-7m5 7V7'
        )
      }
    ]
  },
  {
    label: 'Administration',
    items: [
      {
        to: '/admin',
        label: 'Administration',
        permission: 'system.users.manage|system.roles.manage|system.audit.view',
        icon: icon(
          'M9 11a3.5 3.5 0 1 0 0-7 3.5 3.5 0 0 0 0 7Zm-6 9a6 6 0 0 1 12 0',
          'M17 10v6m-3-3h6'
        )
      },
      {
        to: '/roles',
        label: 'Roles & Permissions',
        permission: 'system.roles.manage',
        icon: icon(
          'M12 3 5 6v5c0 4.5 2.5 7.5 7 10 4.5-2.5 7-5.5 7-10V6l-7-3Z',
          'm9 12 2 2 4-5'
        )
      },
      {
        to: '/settings',
        label: 'Settings',
        permission: 'system.settings.manage',
        icon: icon(
          'M12 8.5a3.5 3.5 0 1 0 0 7 3.5 3.5 0 0 0 0-7Z',
          'M19 12a7 7 0 0 0-.1-1l2-1.5-2-3.5-2.4 1A7 7 0 0 0 15 6l-.3-2.5h-4L10.5 6A7 7 0 0 0 9 7l-2.4-1-2 3.5L6.5 11a7 7 0 0 0 0 2l-2 1.5 2 3.5L9 17a7 7 0 0 0 1.5 1l.2 2.5h4L15 18a7 7 0 0 0 1.5-1l2.4 1 2-3.5L19 13a7 7 0 0 0 0-1Z'
        )
      }
    ]
  }
]
